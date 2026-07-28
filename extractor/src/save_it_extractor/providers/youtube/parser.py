import html
import re
from typing import Any
from urllib.parse import urlsplit

from save_it_extractor.config import settings
from save_it_extractor.domain.models import ProviderContext
from save_it_extractor.providers.errors import ProviderError

VIDEO_ID = re.compile(r"^[A-Za-z0-9_-]{11}$")
FORMAT_ID = re.compile(r"^[A-Za-z0-9._+-]{1,100}$")
THUMBNAIL_HOSTS = frozenset({"i.ytimg.com", "img.youtube.com"})
REJECTED_AVAILABILITY = frozenset(
    {"private", "premium_only", "subscriber_only", "needs_auth", "unlisted_auth"}
)
REJECTED_LIVE_STATUS = frozenset({"is_live", "is_upcoming", "post_live", "was_live"})


def parse_youtube_metadata(payload: dict[str, Any], context: ProviderContext) -> dict[str, Any]:
    video_id = payload.get("id")
    if not isinstance(video_id, str) or VIDEO_ID.fullmatch(video_id) is None:
        raise _changed()
    if video_id not in context.normalized_url:
        raise _changed()

    if payload.get("_type") in {"playlist", "multi_video"} or isinstance(
        payload.get("entries"), list
    ):
        raise ProviderError(
            "playlist_not_supported",
            "YouTube playlists are not supported. Submit a single video URL.",
            422,
            {"provider": "youtube"},
        )

    live_status = payload.get("live_status")
    if (
        payload.get("is_live") is True
        or payload.get("was_live") is True
        or live_status in REJECTED_LIVE_STATUS
    ):
        raise ProviderError(
            "live_not_supported",
            "YouTube live and scheduled live videos are not supported.",
            422,
            {"provider": "youtube"},
        )

    if payload.get("availability") in REJECTED_AVAILABILITY:
        raise ProviderError(
            "authentication_required",
            "This YouTube video is private or requires authentication.",
            422,
            {"provider": "youtube"},
        )

    age_limit = payload.get("age_limit")
    if isinstance(age_limit, (int, float)) and age_limit > 0:
        raise ProviderError(
            "age_restricted",
            "Age-restricted YouTube videos are not supported.",
            422,
            {"provider": "youtube"},
        )

    formats = payload.get("formats")
    if not isinstance(formats, list) or len(formats) > settings.youtube_max_source_formats:
        raise _changed()
    if any(isinstance(item, dict) and item.get("has_drm") is True for item in formats):
        raise ProviderError(
            "drm_protected",
            "DRM-protected YouTube media is not supported.",
            422,
            {"provider": "youtube"},
        )

    video_formats = _video_formats(formats)
    audio_formats = _audio_formats(formats)
    if not video_formats and not audio_formats:
        raise ProviderError(
            "no_media",
            "This YouTube video does not expose supported media formats.",
            422,
            {"provider": "youtube", "status": "no_media"},
        )

    thumbnails = _thumbnails(payload.get("thumbnails"))
    fallback_thumbnail = _safe_thumbnail(payload.get("thumbnail"))
    if fallback_thumbnail and all(item["url"] != fallback_thumbnail for item in thumbnails):
        thumbnails.insert(
            0,
            {"url": fallback_thumbnail, "width": None, "height": None, "preference": None},
        )
        thumbnails = thumbnails[: settings.youtube_max_thumbnails]

    duration = _positive_number(payload.get("duration"))
    title = _clean_text(payload.get("title"), 300)
    if title is None:
        raise _changed()

    return {
        "video_id": video_id,
        "title": title,
        "author_name": _clean_text(payload.get("channel") or payload.get("uploader"), 120),
        "author_handle": _clean_text(payload.get("channel_id") or payload.get("uploader_id"), 120),
        "duration_ms": round(duration * 1000) if duration is not None else None,
        "thumbnail_url": thumbnails[0]["url"] if thumbnails else fallback_thumbnail,
        "thumbnails": thumbnails,
        "video_formats": video_formats,
        "audio_formats": audio_formats,
        "conversion_plans": [
            {
                "id": "mp3",
                "label": "MP3",
                "source": "audio_format",
                "requires_ffmpeg": True,
                "available": False,
            }
        ],
    }


def _video_formats(formats: list[Any]) -> list[dict[str, Any]]:
    results: list[dict[str, Any]] = []
    seen: set[tuple[str, str, str | None, int | None, int | None]] = set()
    for raw in formats:
        if not isinstance(raw, dict) or raw.get("has_drm") is True:
            continue
        format_id = _format_id(raw.get("format_id"))
        container = _container(raw.get("ext"))
        video_codec = _codec(raw.get("vcodec"))
        audio_codec = _codec(raw.get("acodec"))
        if format_id is None or container not in {"mp4", "webm"} or video_codec is None:
            continue

        width = _positive_int(raw.get("width"))
        height = _positive_int(raw.get("height"))
        identity = (format_id, container, video_codec, width, height)
        if identity in seen:
            continue
        seen.add(identity)
        codec_family = _video_codec_family(video_codec)
        results.append(
            {
                "format_id": format_id,
                "container": container,
                "video_codec": video_codec,
                "video_codec_family": codec_family,
                "audio_codec": audio_codec,
                "width": width,
                "height": height,
                "resolution": f"{width}×{height}" if width and height else None,
                "fps": _positive_number(raw.get("fps")),
                "bitrate_kbps": _positive_number(raw.get("tbr") or raw.get("vbr")),
                "estimated_filesize": _positive_int(
                    raw.get("filesize") or raw.get("filesize_approx")
                ),
                "has_audio": audio_codec is not None,
                "requires_merge": audio_codec is None,
                "preference": _video_priority(container, codec_family),
            }
        )

    results.sort(
        key=lambda item: (
            item["preference"],
            -(item["height"] or 0),
            -(item["fps"] or 0),
            -(item["bitrate_kbps"] or 0),
            item["format_id"],
        )
    )
    return results[: settings.youtube_max_video_formats]


def _audio_formats(formats: list[Any]) -> list[dict[str, Any]]:
    results: list[dict[str, Any]] = []
    seen: set[tuple[str, str, str]] = set()
    for raw in formats:
        if not isinstance(raw, dict) or raw.get("has_drm") is True:
            continue
        if _codec(raw.get("vcodec")) is not None:
            continue
        format_id = _format_id(raw.get("format_id"))
        container = _container(raw.get("ext"))
        audio_codec = _codec(raw.get("acodec"))
        if format_id is None or container not in {"m4a", "webm"} or audio_codec is None:
            continue
        identity = (format_id, container, audio_codec)
        if identity in seen:
            continue
        seen.add(identity)
        results.append(
            {
                "format_id": format_id,
                "container": container,
                "audio_codec": audio_codec,
                "bitrate_kbps": _positive_number(raw.get("abr") or raw.get("tbr")),
                "sample_rate_hz": _positive_int(raw.get("asr")),
                "estimated_filesize": _positive_int(
                    raw.get("filesize") or raw.get("filesize_approx")
                ),
                "language": _clean_text(raw.get("language"), 32),
                "preference": 0 if container == "m4a" else 1,
            }
        )

    results.sort(
        key=lambda item: (
            item["preference"],
            -(item["bitrate_kbps"] or 0),
            item["format_id"],
        )
    )
    return results[: settings.youtube_max_audio_formats]


def _thumbnails(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        return []
    if len(value) > 100:
        raise _changed()
    results: list[dict[str, Any]] = []
    seen: set[str] = set()
    for raw in value:
        if not isinstance(raw, dict):
            continue
        url = _safe_thumbnail(raw.get("url"))
        if url is None or url in seen:
            continue
        seen.add(url)
        results.append(
            {
                "url": url,
                "width": _positive_int(raw.get("width")),
                "height": _positive_int(raw.get("height")),
                "preference": _integer(raw.get("preference")),
            }
        )
    results.sort(
        key=lambda item: (
            item["width"] or 0,
            item["height"] or 0,
            item["preference"] or 0,
        ),
        reverse=True,
    )
    return results[: settings.youtube_max_thumbnails]


def _safe_thumbnail(value: Any) -> str | None:
    if not isinstance(value, str):
        return None
    try:
        parsed = urlsplit(value)
    except ValueError:
        return None
    return (
        value
        if parsed.scheme == "https"
        and (parsed.hostname or "").lower() in THUMBNAIL_HOSTS
        and parsed.username is None
        and parsed.password is None
        and parsed.port is None
        else None
    )


def _video_codec_family(codec: str) -> str:
    lowered = codec.lower()
    if lowered.startswith(("avc1", "h264")):
        return "h264"
    if lowered.startswith(("hev1", "hvc1", "hevc", "h265")):
        return "h265"
    return "other"


def _video_priority(container: str, family: str) -> int:
    if container == "mp4" and family == "h264":
        return 0
    if container == "mp4" and family == "h265":
        return 1
    if container == "mp4":
        return 2
    return 3


def _format_id(value: Any) -> str | None:
    return value if isinstance(value, str) and FORMAT_ID.fullmatch(value) else None


def _container(value: Any) -> str | None:
    return value.lower() if isinstance(value, str) else None


def _codec(value: Any) -> str | None:
    if not isinstance(value, str) or value == "none" or len(value) > 80:
        return None
    return value


def _clean_text(value: Any, limit: int) -> str | None:
    if not isinstance(value, str):
        return None
    cleaned = " ".join(html.unescape(value).split())
    return cleaned[:limit] or None


def _positive_number(value: Any) -> float | int | None:
    if isinstance(value, (int, float)) and not isinstance(value, bool) and value > 0:
        return value
    return None


def _positive_int(value: Any) -> int | None:
    return value if isinstance(value, int) and not isinstance(value, bool) and value > 0 else None


def _integer(value: Any) -> int | None:
    return value if isinstance(value, int) and not isinstance(value, bool) else None


def _changed() -> ProviderError:
    return ProviderError(
        "provider_response_changed",
        "YouTube returned metadata in an unsupported format.",
        502,
        {"provider": "youtube"},
    )
