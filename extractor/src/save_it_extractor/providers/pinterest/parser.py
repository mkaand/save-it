from typing import Any
from urllib.parse import urlsplit

from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.pinterest.network import is_allowed_asset_url


def parse_pinterest_pin(
    payload: dict[str, Any], expected_pin_id: str
) -> tuple[dict, list[dict], str]:
    if str(payload.get("id")) != expected_pin_id or payload.get("type") != "pin":
        raise ProviderError(
            "provider_response_changed",
            "Pinterest returned metadata for an unexpected Pin.",
            502,
            {"provider": "pinterest"},
        )

    images = _images(payload.get("images"))
    asset = _video(payload.get("videos"), images) or _image(images)
    if asset is None:
        raise ProviderError(
            "no_media",
            "The public Pinterest Pin does not contain extractable media.",
            422,
            {"provider": "pinterest"},
        )

    pinner = payload.get("pinner") if isinstance(payload.get("pinner"), dict) else {}
    metadata = {
        "post_id": expected_pin_id,
        "text": _text(payload.get("description")) or _text(payload.get("title")),
        "author_name": _text(pinner.get("full_name"), 120),
        "author_handle": _text(pinner.get("username"), 64),
        "published_at": None,
        "thumbnail_url": asset.get("thumbnail_url"),
        "media_count": 1,
    }
    return metadata, [asset], asset["type"]


def _images(raw: Any) -> list[dict]:
    if not isinstance(raw, dict):
        return []

    values = []
    for name, candidate in raw.items():
        if not isinstance(candidate, dict):
            continue
        url = candidate.get("url")
        width = _positive(candidate.get("width"))
        height = _positive(candidate.get("height"))
        if isinstance(url, str) and width and height and is_allowed_asset_url(url):
            values.append({"name": str(name), "url": url, "width": width, "height": height})

    return sorted(
        values,
        key=lambda item: (
            item["width"] * item["height"],
            item["width"],
            item["height"],
            item["url"],
        ),
        reverse=True,
    )


def _image(images: list[dict]) -> dict | None:
    if not images:
        return None

    chosen = images[0]
    return {
        "id": "asset-1",
        "order": 1,
        "type": "image",
        "role": "primary",
        "url": chosen["url"],
        "thumbnail_url": _preview_image(images)["url"],
        "mime_type": _image_mime(chosen["url"]),
        "width": chosen["width"],
        "height": chosen["height"],
        "duration_ms": None,
        "alt_text": None,
        "variants": [],
    }


def _video(raw: Any, images: list[dict]) -> dict | None:
    video_list = raw.get("video_list") if isinstance(raw, dict) else None
    if not isinstance(video_list, dict):
        return None

    candidates = []
    for name, candidate in video_list.items():
        if not isinstance(candidate, dict):
            continue
        url = candidate.get("url")
        width = _positive(candidate.get("width"))
        height = _positive(candidate.get("height"))
        if (
            isinstance(url, str)
            and urlsplit(url).path.lower().endswith(".mp4")
            and is_allowed_asset_url(url)
        ):
            candidates.append({"url": url, "width": width, "height": height, "name": str(name)})

    if not candidates:
        return None
    candidates.sort(
        key=lambda item: (
            (item["width"] or 0) * (item["height"] or 0),
            item["width"] or 0,
            item["height"] or 0,
            item["url"],
        ),
        reverse=True,
    )
    variants = []
    for index, item in enumerate(candidates[:8]):
        variants.append(
            {
                "url": item["url"],
                "mime_type": "video/mp4",
                "protocol": "https",
                "bitrate": None,
                "width": item["width"],
                "height": item["height"],
                "fps": None,
                "container": "mp4",
                "quality_label": (
                    f"{item['width']}×{item['height']}"
                    if item["width"] and item["height"]
                    else item["name"]
                ),
                "filesize": None,
                "is_preferred": index == 0,
            }
        )

    selected = candidates[0]
    return {
        "id": "asset-1",
        "order": 1,
        "type": "video",
        "role": "primary",
        "url": selected["url"],
        "thumbnail_url": _preview_image(images)["url"] if images else None,
        "mime_type": "video/mp4",
        "width": selected["width"],
        "height": selected["height"],
        "duration_ms": None,
        "alt_text": None,
        "variants": variants,
    }


def _positive(value: Any) -> int | None:
    return value if isinstance(value, int) and value > 0 else None


def _preview_image(images: list[dict]) -> dict:
    """Pick the largest structured rendition RecentPreviewStore can materialize.

    The original Pin asset remains the download source. Pinterest's image map also
    includes bounded renditions, so using one here avoids silently turning a
    durable preview failure into an unavailable Recent Fetch.
    """
    bounded = [image for image in images if image["width"] <= 1600 and image["height"] <= 1600]
    return bounded[0] if bounded else images[0]


def _text(value: Any, limit: int = 500) -> str | None:
    if not isinstance(value, str):
        return None
    return " ".join(value.split())[:limit] or None


def _image_mime(url: str) -> str | None:
    path = urlsplit(url).path
    suffix = path.rsplit(".", 1)[-1].lower() if "." in path else ""
    mime_types = {
        "jpg": "image/jpeg",
        "jpeg": "image/jpeg",
        "png": "image/png",
        "webp": "image/webp",
    }
    return mime_types.get(suffix)
