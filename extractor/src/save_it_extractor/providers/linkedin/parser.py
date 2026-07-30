import html
import json
import re
from datetime import datetime
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urlsplit

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.linkedin.network import is_allowed_asset_url

AUTHWALL_MARKERS = (
    "/authwall",
    "authwall-",
    "login__form",
    "/uas/login",
    "checkpoint/challenge",
    'id="join-form"',
)
UNAVAILABLE_MARKERS = (
    "this post is no longer available",
    "this content isn&#39;t available",
    "this content isn't available",
)
STRUCTURED_TYPES = (
    "SocialMediaPosting",
    "VideoObject",
    "ImageObject",
    "Article",
    "NewsArticle",
)
VIDEO_QUALITY_PATH = re.compile(r"(?:^|/)mp4-([0-9]{3,4})p-([0-9]{1,3})fp(?:[-/]|$)")
ISO_DURATION = re.compile(
    r"^PT(?:(?P<hours>[0-9]+)H)?(?:(?P<minutes>[0-9]+)M)?"
    r"(?:(?P<seconds>[0-9]+(?:\.[0-9]+)?)S)?$"
)


class LinkedInDocument(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.meta: dict[str, list[str]] = {}
        self.title_parts: list[str] = []
        self.json_ld: list[Any] = []
        self.video_elements: list[dict[str, Any]] = []
        self._in_title = False
        self._in_json_ld = False
        self._json_parts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = {key.lower(): value for key, value in attrs}
        lowered_tag = tag.lower()
        if lowered_tag == "meta":
            key = (attributes.get("property") or attributes.get("name") or "").lower()
            content = attributes.get("content")
            if key and isinstance(content, str):
                self.meta.setdefault(key, []).append(content)
        elif lowered_tag == "title":
            self._in_title = True
        elif (
            lowered_tag == "script"
            and (attributes.get("type") or "").lower() == "application/ld+json"
        ):
            self._in_json_ld = True
            self._json_parts = []
        elif lowered_tag == "video":
            self.video_elements.append(_video_element(attributes))

    def handle_data(self, data: str) -> None:
        if self._in_title:
            self.title_parts.append(data)
        if self._in_json_ld:
            self._json_parts.append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() == "title":
            self._in_title = False
        elif tag.lower() == "script" and self._in_json_ld:
            self._in_json_ld = False
            try:
                value = json.loads("".join(self._json_parts))
            except json.JSONDecodeError:
                return
            self.json_ld.append(value)

    def first(self, *keys: str) -> str | None:
        for key in keys:
            for value in self.meta.get(key, []):
                cleaned = _clean_text(value, 500)
                if cleaned:
                    return cleaned
        return None

    def all(self, *keys: str) -> list[str]:
        values: list[str] = []
        for key in keys:
            values.extend(self.meta.get(key, []))
        return values


def parse_linkedin_document(
    document: str,
    normalized_url: str,
) -> tuple[dict[str, Any], list[dict[str, Any]], str]:
    parsed = LinkedInDocument()
    try:
        parsed.feed(document)
        parsed.close()
    except (ValueError, RecursionError) as exception:
        raise ProviderError(
            "parsing_failed",
            "LinkedIn returned metadata in an unsupported format.",
            502,
            {"provider": "linkedin"},
        ) from exception

    structured_objects = _structured_objects(parsed.json_ld)
    structured = _structured_post(structured_objects)
    video_object = _first_structured(structured_objects, "VideoObject")
    image_object = _first_structured(structured_objects, "ImageObject")

    title = parsed.first("og:title", "twitter:title") or _structured_text(
        structured, "headline", "name"
    )
    description = parsed.first("og:description", "twitter:description") or _structured_text(
        structured, "description", "text"
    )
    if title is None:
        title = _clean_text(" ".join(parsed.title_parts), 300)
        if title and title.lower() in {
            "linkedin",
            "linkedin login, sign in",
            "sign in | linkedin",
        }:
            title = None

    author_name = (
        _structured_author(structured)
        or _structured_author(video_object)
        or parsed.first("article:author", "author")
    )
    published_at = _published_at(
        parsed.first("article:published_time")
        or _structured_value(structured, "datePublished", "uploadDate")
        or _structured_value(video_object, "datePublished", "uploadDate")
    )

    images = _deduplicated_urls(
        parsed.all("og:image:secure_url", "og:image", "twitter:image")
        + _structured_images(structured_objects)
        + [
            element["poster_url"]
            for element in parsed.video_elements
            if isinstance(element.get("poster_url"), str)
        ]
    )
    variants = _video_variants(parsed, structured_objects)
    assets = (
        [_video_asset(parsed, video_object, variants, images)]
        if variants
        else _image_assets(parsed, images, image_object)
    )

    reliable_public_metadata = bool(
        assets
        or structured
        or video_object
        or image_object
        or any((title, description, author_name, published_at))
    )
    lowered = document[:1_000_000].lower()
    if not reliable_public_metadata:
        if any(marker in lowered for marker in AUTHWALL_MARKERS):
            raise ProviderError(
                "authentication_required",
                "This LinkedIn post requires signing in and cannot be analyzed anonymously.",
                422,
                {"provider": "linkedin"},
            )
        if any(marker in lowered for marker in UNAVAILABLE_MARKERS):
            raise ProviderError(
                "unavailable_content",
                "This LinkedIn post is private or unavailable.",
                422,
                {"provider": "linkedin"},
            )
        raise ProviderError(
            "parsing_failed",
            "LinkedIn did not expose reliable public post metadata.",
            502,
            {"provider": "linkedin"},
        )

    if variants:
        media_type = "video"
    elif len(assets) > 1:
        media_type = "carousel"
    elif assets:
        media_type = assets[0]["type"]
    else:
        media_type = "text"

    thumbnail = next(
        (asset.get("thumbnail_url") for asset in assets if asset.get("thumbnail_url")),
        images[0] if images else None,
    )
    width = _positive_int(_structured_value(video_object, "width"))
    height = _positive_int(_structured_value(video_object, "height"))
    duration_ms = _duration_ms(_structured_value(video_object, "duration"))
    metadata = {
        "post_id": _post_identifier(normalized_url),
        "title": _clean_text(title, 300),
        "description": _clean_text(description, 500),
        "author_name": _clean_text(author_name, 120),
        "author_handle": None,
        "published_at": published_at,
        "thumbnail_url": thumbnail,
        "media_count": len(assets),
        "duration_ms": duration_ms,
        "width": width,
        "height": height,
        "orientation": _orientation(width, height),
        "captions_url": _first_video_metadata(parsed.video_elements, "captions_url"),
        "media_asset_id": _first_video_metadata(parsed.video_elements, "asset_urn"),
    }
    return metadata, assets, media_type


def _video_element(attributes: dict[str, str | None]) -> dict[str, Any]:
    sources: list[dict[str, Any]] = []
    raw_sources = attributes.get("data-sources")
    if isinstance(raw_sources, str) and len(raw_sources) <= 100_000:
        try:
            decoded = json.loads(html.unescape(raw_sources))
        except (json.JSONDecodeError, TypeError):
            decoded = []
        if isinstance(decoded, list):
            for source in decoded[:24]:
                if not isinstance(source, dict):
                    continue
                url = source.get("src")
                if not isinstance(url, str) or not is_allowed_asset_url(url):
                    continue
                sources.append(
                    {
                        "url": url,
                        "mime_type": (
                            source.get("type")
                            if source.get("type") in {"video/mp4", "application/x-mpegURL"}
                            else None
                        ),
                        "bitrate": _positive_int(source.get("data-bitrate")),
                        "width": _positive_int(source.get("width")),
                        "height": _positive_int(source.get("height")),
                    }
                )
    return {
        "sources": sources,
        "poster_url": _safe_optional_asset(attributes.get("data-poster-url")),
        "captions_url": _safe_optional_asset(attributes.get("data-captions-url")),
        "asset_urn": _safe_asset_urn(attributes.get("data-digitalmedia-asset-urn")),
    }


def _video_variants(
    parsed: LinkedInDocument,
    structured_objects: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []
    for element in parsed.video_elements:
        candidates.extend(element["sources"])

    for url in _deduplicated_urls(
        parsed.all("og:video:secure_url", "og:video") + _structured_videos(structured_objects)
    ):
        candidates.append(
            {
                "url": url,
                "mime_type": ("video/mp4" if urlsplit(url).path.lower().endswith(".mp4") else None),
                "bitrate": None,
                "width": None,
                "height": None,
            }
        )

    normalized: list[dict[str, Any]] = []
    seen: set[str] = set()
    for candidate in candidates:
        url = candidate["url"]
        if url in seen or not is_allowed_asset_url(url):
            continue
        seen.add(url)
        quality, fps = _quality_from_url(url)
        mime_type = candidate.get("mime_type")
        path = urlsplit(url).path.lower()
        if mime_type is None and path.endswith(".mp4"):
            mime_type = "video/mp4"
        protocol = "hls" if mime_type == "application/x-mpegURL" else "https"
        width = candidate.get("width") or quality
        normalized.append(
            {
                "url": url,
                "mime_type": mime_type,
                "protocol": protocol,
                "bitrate": candidate.get("bitrate"),
                "width": width,
                "height": candidate.get("height"),
                "fps": fps,
                "container": (
                    "mp4" if mime_type == "video/mp4" else ("hls" if protocol == "hls" else None)
                ),
                "quality_label": (
                    f"{quality}p MP4" if quality is not None and mime_type == "video/mp4" else None
                ),
                "filesize": None,
                "is_preferred": False,
            }
        )

    normalized.sort(
        key=lambda item: (
            item["mime_type"] != "video/mp4",
            -(item["width"] or 0),
            -(item["bitrate"] or 0),
            item["url"],
        )
    )
    return [{**item, "is_preferred": index == 0} for index, item in enumerate(normalized[:12])]


def _video_asset(
    parsed: LinkedInDocument,
    video_object: dict[str, Any],
    variants: list[dict[str, Any]],
    images: list[str],
) -> dict[str, Any]:
    width = _positive_int(_structured_value(video_object, "width"))
    height = _positive_int(_structured_value(video_object, "height"))
    return {
        "id": "asset-1",
        "order": 1,
        "type": "video",
        "role": "primary",
        "url": variants[0]["url"],
        "thumbnail_url": images[0] if images else None,
        "mime_type": variants[0]["mime_type"],
        "width": width,
        "height": height,
        "duration_ms": _duration_ms(_structured_value(video_object, "duration")),
        "alt_text": None,
        "variants": variants,
    }


def _image_assets(
    parsed: LinkedInDocument,
    images: list[str],
    image_object: dict[str, Any],
) -> list[dict[str, Any]]:
    width = _positive_int(
        parsed.first("og:image:width") or _structured_value(image_object, "width")
    )
    height = _positive_int(
        parsed.first("og:image:height") or _structured_value(image_object, "height")
    )
    return [
        {
            "id": f"asset-{index}",
            "order": index,
            "type": "image",
            "role": "primary" if index == 1 else "gallery",
            "url": image,
            "thumbnail_url": image,
            "mime_type": _image_mime(image, parsed.first("og:image:type")),
            "width": width if index == 1 else None,
            "height": height if index == 1 else None,
            "duration_ms": None,
            "alt_text": None,
            "variants": [],
        }
        for index, image in enumerate(images[: settings.linkedin_max_assets], start=1)
    ]


def _structured_objects(values: list[Any]) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    queue = list(values)
    while queue:
        value = queue.pop(0)
        if isinstance(value, list):
            queue.extend(value)
            continue
        if not isinstance(value, dict):
            continue
        result.append(value)
        graph = value.get("@graph")
        if isinstance(graph, list):
            queue.extend(graph)
    return result


def _structured_post(values: list[dict[str, Any]]) -> dict[str, Any]:
    for expected in STRUCTURED_TYPES:
        found = _first_structured(values, expected)
        if found:
            return found
    return {}


def _first_structured(values: list[dict[str, Any]], expected: str) -> dict[str, Any]:
    for value in values:
        kind = value.get("@type")
        kinds = kind if isinstance(kind, list) else [kind]
        if expected in kinds:
            return value
    return {}


def _structured_value(value: dict[str, Any], *keys: str) -> Any:
    for key in keys:
        candidate = value.get(key)
        if candidate is not None:
            return candidate
    return None


def _structured_text(value: dict[str, Any], *keys: str) -> str | None:
    candidate = _structured_value(value, *keys)
    return candidate if isinstance(candidate, str) else None


def _structured_author(value: dict[str, Any]) -> str | None:
    author = value.get("author") or value.get("creator")
    if isinstance(author, list):
        author = author[0] if author else None
    if isinstance(author, dict):
        return author.get("name") if isinstance(author.get("name"), str) else None
    return author if isinstance(author, str) else None


def _structured_images(values: list[dict[str, Any]]) -> list[str]:
    result: list[str] = []
    for value in values:
        result.extend(_structured_urls(value.get("image")))
        result.extend(_structured_urls(value.get("thumbnailUrl")))
    return result


def _structured_videos(values: list[dict[str, Any]]) -> list[str]:
    result: list[str] = []
    for value in values:
        kind = value.get("@type")
        kinds = kind if isinstance(kind, list) else [kind]
        if "VideoObject" in kinds:
            result.extend(
                _structured_urls(
                    {
                        "contentUrl": value.get("contentUrl"),
                        "url": value.get("url"),
                    }
                )
            )
        result.extend(_structured_urls(value.get("video"), include_thumbnail=False))
    return result


def _structured_urls(value: Any, *, include_thumbnail: bool = True) -> list[str]:
    if isinstance(value, str):
        return [value]
    if isinstance(value, list):
        result: list[str] = []
        for item in value:
            result.extend(_structured_urls(item, include_thumbnail=include_thumbnail))
        return result
    if isinstance(value, dict):
        keys = ("contentUrl", "url", "thumbnailUrl") if include_thumbnail else ("contentUrl", "url")
        return [candidate for key in keys if isinstance((candidate := value.get(key)), str)]
    return []


def _deduplicated_urls(values: list[str]) -> list[str]:
    result: list[str] = []
    seen: set[str] = set()
    for value in values:
        cleaned = html.unescape(value).strip()
        if cleaned in seen or not is_allowed_asset_url(cleaned):
            continue
        seen.add(cleaned)
        result.append(cleaned)
    return result


def _post_identifier(normalized_url: str) -> str:
    path = urlsplit(normalized_url).path.strip("/")
    if path.startswith("feed/update/urn:li:activity:"):
        return path.rsplit(":", 1)[-1]
    match = re.search(r"(?:^|[-_])activity[-_:]([0-9]{6,30})(?:[-_]|$)", path)
    return match.group(1) if match else path.removeprefix("posts/")[:300]


def _published_at(value: Any) -> str | None:
    if not isinstance(value, str) or len(value) > 64:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    return parsed.isoformat().replace("+00:00", "Z")


def _clean_text(value: Any, limit: int) -> str | None:
    if not isinstance(value, str):
        return None
    cleaned = " ".join(html.unescape(value).split())
    return cleaned[:limit] or None


def _positive_int(value: Any) -> int | None:
    if isinstance(value, str) and value.isdigit():
        value = int(value)
    return value if isinstance(value, int) and value > 0 else None


def _duration_ms(value: Any) -> int | None:
    if isinstance(value, str):
        iso_match = ISO_DURATION.fullmatch(value)
        if iso_match:
            hours = int(iso_match.group("hours") or 0)
            minutes = int(iso_match.group("minutes") or 0)
            seconds = float(iso_match.group("seconds") or 0)
            duration = (hours * 3600) + (minutes * 60) + seconds
            return round(duration * 1000) if duration > 0 else None
        try:
            value = float(value)
        except ValueError:
            return None
    return round(value * 1000) if isinstance(value, (int, float)) and value > 0 else None


def _quality_from_url(url: str) -> tuple[int | None, int | None]:
    match = VIDEO_QUALITY_PATH.search(urlsplit(url).path)
    return (int(match.group(1)), int(match.group(2))) if match else (None, None)


def _orientation(width: int | None, height: int | None) -> str | None:
    if width is None or height is None:
        return None
    if width == height:
        return "square"
    return "portrait" if height > width else "landscape"


def _first_video_metadata(
    video_elements: list[dict[str, Any]],
    key: str,
) -> str | None:
    return next(
        (value for element in video_elements if isinstance((value := element.get(key)), str)),
        None,
    )


def _safe_optional_asset(value: str | None) -> str | None:
    return value if isinstance(value, str) and is_allowed_asset_url(value) else None


def _safe_asset_urn(value: str | None) -> str | None:
    if not isinstance(value, str):
        return None
    return value if re.fullmatch(r"urn:li:digitalmediaAsset:[A-Za-z0-9_-]{5,128}", value) else None


def _image_mime(url: str, declared: str | None) -> str | None:
    if declared in {"image/jpeg", "image/png", "image/webp"}:
        return declared
    path = urlsplit(url).path.lower()
    if path.endswith((".jpg", ".jpeg")):
        return "image/jpeg"
    if path.endswith(".png"):
        return "image/png"
    if path.endswith(".webp"):
        return "image/webp"
    return None
