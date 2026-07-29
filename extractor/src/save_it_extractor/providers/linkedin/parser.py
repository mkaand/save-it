import html
import json
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


class LinkedInDocument(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.meta: dict[str, list[str]] = {}
        self.title_parts: list[str] = []
        self.json_ld: list[Any] = []
        self._in_title = False
        self._in_json_ld = False
        self._json_parts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = {key.lower(): value for key, value in attrs}
        if tag.lower() == "meta":
            key = (attributes.get("property") or attributes.get("name") or "").lower()
            content = attributes.get("content")
            if key and isinstance(content, str):
                self.meta.setdefault(key, []).append(content)
        elif tag.lower() == "title":
            self._in_title = True
        elif (
            tag.lower() == "script"
            and (attributes.get("type") or "").lower() == "application/ld+json"
        ):
            self._in_json_ld = True
            self._json_parts = []

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
    lowered = document[:1_000_000].lower()
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

    structured = _structured_post(parsed.json_ld)
    title = parsed.first("og:title", "twitter:title") or _structured_text(
        structured, "headline", "name"
    )
    description = parsed.first("og:description", "twitter:description") or _structured_text(
        structured, "description", "text"
    )
    if title is None:
        title = _clean_text(" ".join(parsed.title_parts), 300)
        if title and title.lower() in {"linkedin", "linkedin login, sign in"}:
            title = None

    author_name = _structured_author(structured) or parsed.first("article:author", "author")
    published_at = _published_at(
        parsed.first("article:published_time")
        or (structured.get("datePublished") if isinstance(structured, dict) else None)
    )

    images = _deduplicated_urls(
        parsed.all("og:image:secure_url", "og:image", "twitter:image")
        + _structured_images(structured)
    )
    videos = _deduplicated_urls(
        parsed.all("og:video:secure_url", "og:video") + _structured_videos(structured)
    )
    assets = _assets(parsed, images, videos)

    if not assets and not any((title, description, author_name, published_at)):
        raise ProviderError(
            "parsing_failed",
            "LinkedIn did not expose reliable public post metadata.",
            502,
            {"provider": "linkedin"},
        )

    if videos and len(assets) == 1:
        media_type = "video"
    elif len(assets) > 1:
        media_type = "carousel"
    elif assets:
        media_type = assets[0]["type"]
    else:
        media_type = "text"

    thumbnail = next(
        (asset.get("thumbnail_url") for asset in assets if asset.get("thumbnail_url")),
        None,
    )
    metadata = {
        "post_id": _post_identifier(normalized_url),
        "title": _clean_text(title, 300),
        "description": _clean_text(description, 500),
        "author_name": _clean_text(author_name, 120),
        "author_handle": None,
        "published_at": published_at,
        "thumbnail_url": thumbnail,
        "media_count": len(assets),
    }
    return metadata, assets, media_type


def _assets(
    parsed: LinkedInDocument,
    images: list[str],
    videos: list[str],
) -> list[dict[str, Any]]:
    assets: list[dict[str, Any]] = []
    image_width = _positive_int(parsed.first("og:image:width"))
    image_height = _positive_int(parsed.first("og:image:height"))
    video_width = _positive_int(parsed.first("og:video:width"))
    video_height = _positive_int(parsed.first("og:video:height"))
    video_duration = _duration_ms(parsed.first("og:video:duration"))
    thumbnail = images[0] if images else None

    for video in videos[: settings.linkedin_max_assets]:
        mime_type = parsed.first("og:video:type")
        if mime_type not in {"video/mp4", "application/x-mpegURL"}:
            mime_type = "video/mp4" if urlsplit(video).path.lower().endswith(".mp4") else None
        assets.append(
            {
                "id": f"asset-{len(assets) + 1}",
                "order": len(assets) + 1,
                "type": "video",
                "role": "primary" if not assets else "gallery",
                "url": video,
                "thumbnail_url": thumbnail,
                "mime_type": mime_type,
                "width": video_width,
                "height": video_height,
                "duration_ms": video_duration,
                "alt_text": None,
                "variants": [],
            }
        )

    image_start = 1 if videos and images else 0
    available_slots = settings.linkedin_max_assets - len(assets)
    for image in images[image_start : image_start + available_slots]:
        assets.append(
            {
                "id": f"asset-{len(assets) + 1}",
                "order": len(assets) + 1,
                "type": "image",
                "role": "primary" if not assets else "gallery",
                "url": image,
                "thumbnail_url": image,
                "mime_type": _image_mime(image, parsed.first("og:image:type")),
                "width": image_width if not assets else None,
                "height": image_height if not assets else None,
                "duration_ms": None,
                "alt_text": None,
                "variants": [],
            }
        )
    return assets


def _structured_post(values: list[Any]) -> dict[str, Any]:
    queue = list(values)
    while queue:
        value = queue.pop(0)
        if isinstance(value, list):
            queue.extend(value)
            continue
        if not isinstance(value, dict):
            continue
        graph = value.get("@graph")
        if isinstance(graph, list):
            queue.extend(graph)
        kind = value.get("@type")
        kinds = kind if isinstance(kind, list) else [kind]
        if any(item in {"SocialMediaPosting", "Article", "NewsArticle"} for item in kinds):
            return value
    return {}


def _structured_text(value: dict[str, Any], *keys: str) -> str | None:
    for key in keys:
        candidate = value.get(key)
        if isinstance(candidate, str):
            return candidate
    return None


def _structured_author(value: dict[str, Any]) -> str | None:
    author = value.get("author")
    if isinstance(author, list):
        author = author[0] if author else None
    if isinstance(author, dict):
        return author.get("name") if isinstance(author.get("name"), str) else None
    return author if isinstance(author, str) else None


def _structured_images(value: dict[str, Any]) -> list[str]:
    return _structured_urls(value.get("image"))


def _structured_videos(value: dict[str, Any]) -> list[str]:
    return _structured_urls(value.get("video"), include_thumbnail=False)


def _structured_urls(value: Any, *, include_thumbnail: bool = True) -> list[str]:
    if isinstance(value, str):
        return [value]
    if isinstance(value, list):
        result: list[str] = []
        for item in value:
            result.extend(_structured_urls(item, include_thumbnail=include_thumbnail))
        return result
    if isinstance(value, dict):
        keys = (
            ("contentUrl", "url", "thumbnailUrl")
            if include_thumbnail
            else (
                "contentUrl",
                "url",
            )
        )
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
    return path.removeprefix("posts/")[:300]


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
        try:
            value = float(value)
        except ValueError:
            return None
    return round(value * 1000) if isinstance(value, (int, float)) and value > 0 else None


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
