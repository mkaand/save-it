import html
import re
from datetime import datetime
from typing import Any
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.x.network import is_allowed_asset_url

DIMENSIONS = re.compile(r"/([1-9][0-9]{1,4})x([1-9][0-9]{1,4})/")


def parse_x_payload(payload: dict[str, Any], expected_id: str) -> tuple[dict, list[dict], str]:
    if str(payload.get("id_str", "")) != expected_id:
        raise ProviderError(
            "provider_response_changed",
            "X returned metadata for an unexpected post.",
            502,
        )

    media = payload.get("mediaDetails")
    if not isinstance(media, list):
        media = []

    assets: list[dict] = []
    seen: set[tuple[str, str]] = set()
    for item in media[: settings.x_max_assets]:
        asset = _asset(item, len(assets) + 1)
        if asset is None:
            continue
        identity = (asset["type"], asset["url"])
        if identity in seen:
            continue
        seen.add(identity)
        assets.append(asset)

    if not assets:
        raise ProviderError(
            "no_media",
            "The public X post does not contain directly attached media.",
            422,
            {"provider": "x", "status": "no_media"},
        )

    media_types = {asset["type"] for asset in assets}
    if len(assets) > 1 and len(media_types) > 1:
        media_type = "mixed_media"
    elif len(assets) > 1:
        media_type = "carousel"
    else:
        media_type = assets[0]["type"]

    text = _clean_text(payload.get("text"), 500)
    user = payload.get("user") if isinstance(payload.get("user"), dict) else {}
    metadata = {
        "post_id": expected_id,
        "text": text,
        "author_name": _clean_text(user.get("name"), 120),
        "author_handle": _clean_handle(user.get("screen_name")),
        "published_at": _published_at(payload.get("created_at")),
        "thumbnail_url": next(
            (asset.get("thumbnail_url") for asset in assets if asset.get("thumbnail_url")),
            None,
        ),
        "media_count": len(assets),
    }
    return metadata, assets, media_type


def _asset(raw: Any, order: int) -> dict | None:
    if not isinstance(raw, dict):
        return None

    media_type = raw.get("type")
    if media_type not in {"photo", "video", "animated_gif"}:
        return None

    original = raw.get("original_info") if isinstance(raw.get("original_info"), dict) else {}
    width = _positive_int(original.get("width"))
    height = _positive_int(original.get("height"))
    preview = _safe_url(raw.get("media_url_https"))

    if media_type == "photo":
        source = _original_image_url(preview)
        if source is None:
            return None
        mime_type = _image_mime(source)
        return {
            "id": f"asset-{order}",
            "order": order,
            "type": "image",
            "role": "primary" if order == 1 else "gallery",
            "url": source,
            "thumbnail_url": preview,
            "mime_type": mime_type,
            "width": width,
            "height": height,
            "duration_ms": None,
            "alt_text": _clean_text(raw.get("ext_alt_text"), 500),
            "variants": [],
        }

    variants = _video_variants(raw.get("video_info"))
    if not variants:
        return None
    best = next((variant for variant in variants if variant["is_preferred"]), variants[0])
    video_info = raw.get("video_info") if isinstance(raw.get("video_info"), dict) else {}
    return {
        "id": f"asset-{order}",
        "order": order,
        "type": "animated_gif" if media_type == "animated_gif" else "video",
        "role": "primary" if order == 1 else "gallery",
        "url": best["url"],
        "thumbnail_url": preview,
        "mime_type": best["mime_type"],
        "width": width,
        "height": height,
        "duration_ms": _positive_int(video_info.get("duration_millis")),
        "alt_text": _clean_text(raw.get("ext_alt_text"), 500),
        "variants": variants,
    }


def _video_variants(raw: Any) -> list[dict]:
    if not isinstance(raw, dict) or not isinstance(raw.get("variants"), list):
        return []

    variants: list[dict] = []
    seen: set[str] = set()
    for candidate in raw["variants"][: settings.x_max_variants * 2]:
        if not isinstance(candidate, dict):
            continue
        url = _safe_url(candidate.get("url"))
        mime = candidate.get("content_type")
        if url is None or mime not in {"video/mp4", "application/x-mpegURL"} or url in seen:
            continue
        seen.add(url)
        dimensions = DIMENSIONS.search(urlsplit(url).path)
        width = int(dimensions.group(1)) if dimensions else None
        height = int(dimensions.group(2)) if dimensions else None
        bitrate = _positive_int(candidate.get("bitrate"))
        variants.append(
            {
                "url": url,
                "mime_type": mime,
                "protocol": "hls" if mime == "application/x-mpegURL" else "https",
                "bitrate": bitrate,
                "width": width,
                "height": height,
                "quality_label": f"{width}×{height}" if width and height else None,
                "is_preferred": False,
            }
        )

    variants.sort(
        key=lambda item: (
            item["mime_type"] == "video/mp4",
            item["height"] or 0,
            item["bitrate"] or 0,
        ),
        reverse=True,
    )
    if variants:
        variants[0]["is_preferred"] = True
    return variants[: settings.x_max_variants]


def _safe_url(value: Any) -> str | None:
    return value if isinstance(value, str) and is_allowed_asset_url(value) else None


def _original_image_url(url: str | None) -> str | None:
    if url is None:
        return None
    parsed = urlsplit(url)
    query = dict(parse_qsl(parsed.query, keep_blank_values=True))
    query["name"] = "orig"
    return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, urlencode(query), ""))


def _image_mime(url: str) -> str | None:
    parsed = urlsplit(url)
    fmt = dict(parse_qsl(parsed.query)).get("format", "").lower()
    if not fmt:
        fmt = parsed.path.rsplit(".", 1)[-1].lower() if "." in parsed.path else ""
    if fmt in {"jpg", "jpeg"}:
        return "image/jpeg"
    if fmt in {"png", "webp"}:
        return f"image/{fmt}"
    return None


def _clean_text(value: Any, limit: int) -> str | None:
    if not isinstance(value, str):
        return None
    cleaned = " ".join(html.unescape(value).split())
    return cleaned[:limit] or None


def _clean_handle(value: Any) -> str | None:
    return value if isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_]{1,15}", value) else None


def _published_at(value: Any) -> str | None:
    if not isinstance(value, str):
        return None
    try:
        return (
            datetime.fromisoformat(value.replace("Z", "+00:00")).isoformat().replace("+00:00", "Z")
        )
    except ValueError:
        return None


def _positive_int(value: Any) -> int | None:
    return value if isinstance(value, int) and value > 0 else None
