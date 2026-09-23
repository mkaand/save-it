"""Identity-bound parser for Facebook's Relay/data-sjs public video state."""

import json
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urlsplit

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.facebook.network import is_allowed_asset_url


class _DataSjsParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=False)
        self._active = False
        self.scripts: list[str] = []
        self._current: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self._active = tag == "script" and "data-sjs" in dict(attrs)
        if self._active:
            self._current = []

    def handle_data(self, data: str) -> None:
        if self._active:
            self._current.append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag == "script" and self._active:
            self.scripts.append("".join(self._current))
            self._active = False


def parse_facebook_video(html: str, expected_video_id: str) -> tuple[dict, list[dict], str]:
    parser = _DataSjsParser()
    parser.feed(html)
    candidates: list[dict[str, Any]] = []
    for script in parser.scripts:
        try:
            state = json.loads(script)
        except json.JSONDecodeError:
            continue
        candidates.extend(_relay_candidates(state, expected_video_id))

    # The Cobalt fields are a bounded fallback only when the page contains exactly
    # one identity-bound Relay candidate. Never select arbitrary feed media.
    unique = _unique_candidates(candidates, expected_video_id)
    if len(unique) != 1:
        raise _changed()
    video = unique[0]
    variants = _variants(video)
    if not variants:
        raise ProviderError(
            "no_media",
            "The public Facebook post does not contain extractable video.",
            422,
            {"provider": "facebook"},
        )
    poster = _poster(video)
    selected = variants[0]
    metadata = {
        "post_id": expected_video_id,
        "text": _text(_first_string(video, "title", "name")),
        "author_name": _owner_name(video),
        "author_handle": None,
        "published_at": None,
        "thumbnail_url": poster,
        "media_count": 1,
    }
    asset = {
        "id": "asset-1",
        "order": 1,
        "type": "video",
        "role": "primary",
        "url": selected["url"],
        "thumbnail_url": poster,
        "mime_type": "video/mp4",
        "width": _positive(video.get("width")),
        "height": _positive(video.get("height")),
        "duration_ms": _duration_ms(video),
        "alt_text": None,
        "variants": [
            {
                "url": item["url"],
                "mime_type": "video/mp4",
                "protocol": "https",
                "bitrate": item["bitrate"],
                "width": item["width"],
                "height": item["height"],
                "fps": None,
                "container": "mp4",
                "quality_label": item["quality_label"],
                "filesize": None,
                "is_preferred": index == 0,
            }
            for index, item in enumerate(variants)
        ],
    }
    return metadata, [asset], "video"


def _relay_candidates(state: Any, expected_video_id: str) -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []

    def visit(value: Any) -> None:
        if isinstance(value, dict):
            # Relay's known response boundary. Limiting candidate discovery to
            # these media fields prevents sidebar/feed assets becoming a result.
            if _identity(value, expected_video_id) and (
                isinstance(value.get("videoDeliveryLegacyFields"), dict)
                or isinstance(value.get("videoDeliveryResponseFragment"), dict)
            ):
                candidates.append(value)
            for child in value.values():
                visit(child)
        elif isinstance(value, list):
            for child in value:
                visit(child)

    visit(state)
    return candidates


def _identity(video: dict[str, Any], expected_video_id: str) -> bool:
    return str(video.get("videoId") or video.get("id") or "") == expected_video_id


def _unique_candidates(
    candidates: list[dict[str, Any]], expected_video_id: str
) -> list[dict[str, Any]]:
    unique: dict[str, dict[str, Any]] = {}
    for candidate in candidates:
        identifier = str(candidate.get("videoId") or candidate.get("id") or "")
        if identifier != expected_video_id:
            continue
        # Same Relay object can occur in more than one data-sjs block. Its
        # normalized media identity is stable while signed query values are not.
        identities = tuple(sorted(_media_identity(item["url"]) for item in _variants(candidate)))
        if identities:
            unique.setdefault("|".join(identities), candidate)
    return list(unique.values())


def _variants(video: dict[str, Any]) -> list[dict[str, Any]]:
    raw: list[dict[str, Any]] = []
    legacy = video.get("videoDeliveryLegacyFields")
    if isinstance(legacy, dict):
        for key, rank, native_quality in (
            ("browser_native_hd_url", 2, "HD"),
            ("playable_url_quality_hd", 2, "HD"),
            ("browser_native_sd_url", 1, "SD"),
            ("playable_url", 1, "SD"),
        ):
            url = legacy.get(key)
            if isinstance(url, str) and is_allowed_asset_url(url):
                raw.append(_candidate(url, video, rank, native_quality))
    response = video.get("videoDeliveryResponseFragment")
    result = response.get("videoDeliveryResponseResult") if isinstance(response, dict) else None
    progressive = result.get("progressive_urls") if isinstance(result, dict) else None
    if isinstance(progressive, list):
        for item in progressive:
            if not isinstance(item, dict) or not isinstance(item.get("progressive_url"), str):
                continue
            url = item["progressive_url"]
            if is_allowed_asset_url(url):
                metadata = item.get("metadata") if isinstance(item.get("metadata"), dict) else {}
                raw.append(_candidate(url, {**video, **metadata}, 0, None))
    deduplicated: dict[str, dict[str, Any]] = {}
    for item in raw:
        identity = _media_identity(item["url"])
        current = deduplicated.get(identity)
        if current is None or _rank(item) < _rank(current):
            deduplicated[identity] = item
    return sorted(deduplicated.values(), key=_rank)[: settings.facebook_max_variants]


def _candidate(
    url: str, metadata: dict[str, Any], quality_rank: int, native_quality: str | None
) -> dict[str, Any]:
    width = _positive(metadata.get("width"))
    height = _positive(metadata.get("height"))
    bitrate = _positive(metadata.get("bitrate"))
    return {
        "url": url,
        "width": width,
        "height": height,
        "bitrate": bitrate,
        "quality_rank": quality_rank,
        "quality_label": _quality_label(width, height, bitrate, native_quality),
    }


def _rank(value: dict[str, Any]) -> tuple[int, int, int, str]:
    return (
        -((value["width"] or 0) * (value["height"] or 0)),
        -(value["bitrate"] or 0),
        -value["quality_rank"],
        _media_identity(value["url"]),
    )


def _media_identity(url: str) -> str:
    return urlsplit(url).path


def _poster(video: dict[str, Any]) -> str | None:
    thumbnail = video.get("preferred_thumbnail")
    image = thumbnail.get("image") if isinstance(thumbnail, dict) else None
    url = image.get("uri") if isinstance(image, dict) else None
    return url if isinstance(url, str) and is_allowed_asset_url(url) else None


def _duration_ms(video: dict[str, Any]) -> int | None:
    duration = video.get("playable_duration_in_ms") or video.get("playable_duration")
    if isinstance(duration, (int, float)) and duration > 0:
        return int(duration if duration > 10_000 else duration * 1000)
    return None


def _owner_name(video: dict[str, Any]) -> str | None:
    owner = video.get("owner")
    if not isinstance(owner, dict):
        return None
    return _text(_first_string(owner, "name"), 120)


def _first_string(values: dict[str, Any], *keys: str) -> str | None:
    return next((value for key in keys if isinstance((value := values.get(key)), str)), None)


def _positive(value: Any) -> int | None:
    return value if isinstance(value, int) and value > 0 else None


def _text(value: Any, limit: int = 500) -> str | None:
    return " ".join(value.split())[:limit] or None if isinstance(value, str) else None


def _quality_label(
    width: int | None, height: int | None, bitrate: int | None, native_quality: str | None
) -> str:
    dimensions = f"{width}×{height}" if width and height else None
    bitrate_label = f"{bitrate / 1_000_000:.1f} Mbps" if bitrate else None
    return (
        " · ".join(value for value in (native_quality, dimensions, bitrate_label) if value) or "MP4"
    )


def _changed() -> ProviderError:
    return ProviderError(
        "provider_response_changed",
        "Facebook returned unsupported public video metadata.",
        502,
        {"provider": "facebook"},
    )
