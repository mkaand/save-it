import html
import json
import re
from datetime import UTC, datetime
from typing import Any
from urllib.parse import urlsplit

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.instagram.network import is_allowed_asset_url

CONTEXT_MARKER = '"contextJSON":'
SHORTCODE = re.compile(r"^[A-Za-z0-9_-]{5,64}$")
USERNAME = re.compile(r"^[A-Za-z0-9._]{1,30}$")


def parse_instagram_embed(
    document: str, expected_shortcode: str, requested_kind: str
) -> tuple[dict, list[dict], str]:
    media = _shortcode_media(document)
    if media.get("shortcode") != expected_shortcode:
        raise ProviderError(
            "provider_response_changed",
            "Instagram returned metadata for unexpected media.",
            502,
        )

    nodes = _media_nodes(media)
    assets: list[dict] = []
    seen: set[tuple[str, str]] = set()
    for node in nodes[: settings.instagram_max_assets]:
        asset = _asset(node, len(assets) + 1)
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
            "The public Instagram post does not contain extractable media.",
            422,
            {"provider": "instagram", "status": "no_media"},
        )

    if len(assets) > 1:
        media_type = "carousel"
    elif requested_kind == "reel":
        media_type = "reel"
    else:
        media_type = assets[0]["type"]

    owner = media.get("owner") if isinstance(media.get("owner"), dict) else {}
    caption = _caption(media)
    username = _username(owner.get("username"))
    thumbnail = next(
        (asset.get("thumbnail_url") for asset in assets if asset.get("thumbnail_url")),
        None,
    )
    metadata = {
        "post_id": expected_shortcode,
        "caption": caption,
        "author_name": _clean_text(owner.get("full_name"), 120),
        "author_handle": username,
        "published_at": _published_at(media.get("taken_at_timestamp")),
        "thumbnail_url": thumbnail,
        "media_count": len(assets),
    }
    return metadata, assets, media_type


def _shortcode_media(document: str) -> dict[str, Any]:
    cursor = 0
    decoder = json.JSONDecoder()
    while True:
        marker = document.find(CONTEXT_MARKER, cursor)
        if marker < 0:
            break
        value_start = marker + len(CONTEXT_MARKER)
        try:
            encoded, _ = decoder.raw_decode(document[value_start:])
            payload = json.loads(encoded) if isinstance(encoded, str) else encoded
        except (json.JSONDecodeError, TypeError):
            cursor = value_start
            continue
        if isinstance(payload, dict):
            gql = payload.get("gql_data")
            media = gql.get("shortcode_media") if isinstance(gql, dict) else None
            if isinstance(media, dict):
                return media
        cursor = value_start

    raise ProviderError(
        "provider_response_changed",
        "Instagram returned metadata in an unsupported format.",
        502,
    )


def _media_nodes(media: dict[str, Any]) -> list[dict[str, Any]]:
    sidecar = media.get("edge_sidecar_to_children")
    edges = sidecar.get("edges") if isinstance(sidecar, dict) else None
    if isinstance(edges, list):
        nodes = [
            edge["node"]
            for edge in edges
            if isinstance(edge, dict) and isinstance(edge.get("node"), dict)
        ]
        if nodes:
            return nodes
    return [media]


def _asset(raw: dict[str, Any], order: int) -> dict | None:
    is_video = raw.get("is_video") is True
    preview = _safe_url(raw.get("display_url") or raw.get("thumbnail_src"))
    width, height = _dimensions(raw)
    if is_video:
        source = _safe_url(raw.get("video_url"))
        if source is None:
            return None
        variants = [
            {
                "url": source,
                "mime_type": "video/mp4",
                "protocol": "https",
                "bitrate": None,
                "width": width,
                "height": height,
                "quality_label": f"{width}×{height}" if width and height else None,
                "is_preferred": True,
            }
        ]
        return {
            "id": f"asset-{order}",
            "order": order,
            "type": "video",
            "role": "primary" if order == 1 else "gallery",
            "url": source,
            "thumbnail_url": preview,
            "mime_type": "video/mp4",
            "width": width,
            "height": height,
            "duration_ms": _duration_ms(raw.get("video_duration")),
            "alt_text": _clean_text(raw.get("accessibility_caption"), 500),
            "variants": variants,
        }

    if preview is None:
        return None
    return {
        "id": f"asset-{order}",
        "order": order,
        "type": "image",
        "role": "primary" if order == 1 else "gallery",
        "url": preview,
        "thumbnail_url": preview,
        "mime_type": _image_mime(preview),
        "width": width,
        "height": height,
        "duration_ms": None,
        "alt_text": _clean_text(raw.get("accessibility_caption"), 500),
        "variants": [],
    }


def _caption(media: dict[str, Any]) -> str | None:
    container = media.get("edge_media_to_caption")
    edges = container.get("edges") if isinstance(container, dict) else None
    if not isinstance(edges, list) or not edges:
        return None
    node = edges[0].get("node") if isinstance(edges[0], dict) else None
    return _clean_text(node.get("text") if isinstance(node, dict) else None, 500)


def _dimensions(raw: dict[str, Any]) -> tuple[int | None, int | None]:
    dimensions = raw.get("dimensions")
    if not isinstance(dimensions, dict):
        return None, None
    return _positive_int(dimensions.get("width")), _positive_int(dimensions.get("height"))


def _safe_url(value: Any) -> str | None:
    return value if isinstance(value, str) and is_allowed_asset_url(value) else None


def _image_mime(url: str) -> str | None:
    path = urlsplit(url).path.lower()
    if path.endswith((".jpg", ".jpeg")):
        return "image/jpeg"
    if path.endswith(".png"):
        return "image/png"
    if path.endswith(".webp"):
        return "image/webp"
    return None


def _clean_text(value: Any, limit: int) -> str | None:
    if not isinstance(value, str):
        return None
    cleaned = " ".join(html.unescape(value).split())
    return cleaned[:limit] or None


def _username(value: Any) -> str | None:
    return value if isinstance(value, str) and USERNAME.fullmatch(value) else None


def _published_at(value: Any) -> str | None:
    if not isinstance(value, int) or value <= 0:
        return None
    try:
        return datetime.fromtimestamp(value, UTC).isoformat().replace("+00:00", "Z")
    except (OverflowError, OSError, ValueError):
        return None


def _duration_ms(value: Any) -> int | None:
    return round(value * 1000) if isinstance(value, (int, float)) and value > 0 else None


def _positive_int(value: Any) -> int | None:
    return value if isinstance(value, int) and value > 0 else None
