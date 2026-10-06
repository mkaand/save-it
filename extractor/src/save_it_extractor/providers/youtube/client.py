import asyncio
import re
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlsplit

from yt_dlp import YoutubeDL
from yt_dlp.utils import DownloadError

from save_it_extractor.config import settings
from save_it_extractor.domain.urls import classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.youtube.parser import parse_youtube_metadata

CANONICAL_YOUTUBE_URL = re.compile(
    r"^https://www\.youtube\.com/(?:watch\?v=[A-Za-z0-9_-]{11}|shorts/[A-Za-z0-9_-]{11})$"
)


class _QuietLogger:
    def debug(self, _: str) -> None:
        pass

    def warning(self, _: str) -> None:
        pass

    def error(self, _: str) -> None:
        pass


@dataclass(frozen=True, slots=True)
class YouTubeMetadataClient:
    extractor_factory: Callable[[dict[str, Any]], Any] = YoutubeDL

    async def fetch(self, canonical_url: str) -> dict[str, Any]:
        if CANONICAL_YOUTUBE_URL.fullmatch(canonical_url) is None:
            raise ProviderError(
                "invalid_youtube_video_url",
                "Enter a valid YouTube video or Shorts URL.",
                422,
                {"provider": "youtube"},
            )

        adaptive = await asyncio.to_thread(self._fetch_sync, canonical_url, "default")
        # Android still exposes the small progressive format that keeps the
        # established direct 360p path available. Its adaptive URLs require a
        # PO token on current YouTube delivery, so they must not displace the
        # anonymous default client's usable adaptive formats.
        try:
            progressive = await asyncio.to_thread(self._fetch_sync, canonical_url, "android")
        except ProviderError:
            return _tag_formats(adaptive, "default")

        return _combine_formats(adaptive, progressive)

    async def resolve_format(
        self, canonical_url: str, format_id: str, source_client: str = "android"
    ) -> dict[str, Any]:
        if source_client not in {"default", "android"}:
            raise ProviderError(
                "format_unavailable",
                "The selected YouTube format is no longer available.",
                410,
                {"provider": "youtube"},
            )
        payload = await asyncio.to_thread(self._fetch_sync, canonical_url, source_client)
        metadata = parse_youtube_metadata(payload, classify_url(canonical_url))
        allowed = {
            item["format_id"] for item in metadata["video_formats"] + metadata["audio_formats"]
        }
        formats = payload.get("formats")
        if not isinstance(formats, list):
            raise ProviderError(
                "provider_response_changed",
                "YouTube returned metadata in an unsupported format.",
                502,
                {"provider": "youtube"},
            )
        selected = next(
            (
                item
                for item in formats
                if isinstance(item, dict)
                and item.get("format_id") == format_id
                and format_id in allowed
            ),
            None,
        )
        if not isinstance(selected, dict):
            raise ProviderError(
                "format_unavailable",
                "The selected YouTube format is no longer available.",
                410,
                {"provider": "youtube"},
            )
        source_url = _safe_googlevideo_url(selected.get("url"))
        if source_url is None:
            raise ProviderError(
                "provider_response_changed",
                "YouTube returned an unsafe media source.",
                502,
                {"provider": "youtube"},
            )

        return {
            "url": source_url,
            "mime_type": _format_mime(selected),
            "estimated_filesize": _positive_int(
                selected.get("filesize") or selected.get("filesize_approx")
            ),
            "video_codec": selected.get("vcodec"),
            "audio_codec": selected.get("acodec"),
        }

    def _fetch_sync(self, canonical_url: str, source_client: str) -> dict[str, Any]:
        options: dict[str, Any] = {
            "cachedir": False,
            "cookiefile": None,
            "download": False,
            "extract_flat": False,
            "allowed_extractors": ["Youtube"],
            "fragment_retries": 0,
            "ignoreconfig": True,
            "logger": _QuietLogger(),
            "noplaylist": True,
            "playlist_items": "1",
            "proxy": "",
            "quiet": True,
            "retries": 1,
            "simulate": True,
            "skip_download": True,
            "socket_timeout": settings.youtube_socket_timeout_seconds,
            "writesubtitles": False,
            "writeautomaticsub": False,
            "writethumbnail": False,
            "js_runtimes": {},
            "remote_components": set(),
        }
        if source_client == "android":
            options["extractor_args"] = {"youtube": {"player_client": ["android"]}}

        # yt-dlp owns its metadata networking stack and does not expose a
        # per-request DNS pinning hook. Canonical input is restricted here and
        # every returned media URL is revalidated by Save It's pinned delivery
        # policy before any binary request is made.

        try:
            with self.extractor_factory(options) as extractor:
                result = extractor.extract_info(canonical_url, download=False)
        except DownloadError as exception:
            raise _safe_download_error(exception) from exception
        except (OSError, ValueError) as exception:
            raise ProviderError(
                "upstream_unavailable",
                "YouTube metadata is temporarily unavailable.",
                503,
                {"provider": "youtube"},
            ) from exception

        if not isinstance(result, dict):
            raise ProviderError(
                "provider_response_changed",
                "YouTube returned metadata in an unsupported format.",
                502,
                {"provider": "youtube"},
            )

        return result


def _tag_formats(payload: dict[str, Any], source_client: str) -> dict[str, Any]:
    formats = payload.get("formats")
    if not isinstance(formats, list):
        return payload
    return {
        **payload,
        "formats": [
            {**item, "_save_it_source_client": source_client} if isinstance(item, dict) else item
            for item in formats
        ],
    }


def _combine_formats(adaptive: dict[str, Any], progressive: dict[str, Any]) -> dict[str, Any]:
    tagged_adaptive = _tag_formats(adaptive, "default")
    tagged_progressive = _tag_formats(progressive, "android")
    base = tagged_adaptive.get("formats")
    extra = tagged_progressive.get("formats")
    if not isinstance(base, list) or not isinstance(extra, list):
        return tagged_adaptive
    existing = {
        (item.get("format_id"), item.get("ext"), item.get("vcodec"), item.get("acodec"))
        for item in base
        if isinstance(item, dict)
    }
    # Keep only truly progressive Android formats; default-client adaptive
    # tracks remain the authoritative high-resolution source.
    additions = [
        item
        for item in extra
        if isinstance(item, dict)
        and item.get("vcodec") not in {None, "none"}
        and item.get("acodec") not in {None, "none"}
        and (item.get("format_id"), item.get("ext"), item.get("vcodec"), item.get("acodec"))
        not in existing
    ]
    return {**tagged_adaptive, "formats": [*base, *additions]}


def _safe_download_error(exception: DownloadError) -> ProviderError:
    message = str(exception).lower()
    if "private video" in message or "sign in" in message or "login" in message:
        return ProviderError(
            "authentication_required",
            "This YouTube video is private or requires authentication.",
            422,
            {"provider": "youtube"},
        )
    if "age" in message and ("restricted" in message or "confirm" in message):
        return ProviderError(
            "age_restricted",
            "Age-restricted YouTube videos are not supported.",
            422,
            {"provider": "youtube"},
        )
    if "live event will begin" in message or "upcoming" in message:
        return ProviderError(
            "live_not_supported",
            "YouTube live and scheduled live videos are not supported.",
            422,
            {"provider": "youtube"},
        )
    if "unavailable" in message or "removed" in message:
        return ProviderError(
            "video_unavailable",
            "This YouTube video is unavailable.",
            422,
            {"provider": "youtube"},
        )
    return ProviderError(
        "upstream_unavailable",
        "YouTube metadata is temporarily unavailable.",
        503,
        {"provider": "youtube"},
    )


def _safe_googlevideo_url(value: Any) -> str | None:
    if not isinstance(value, str):
        return None
    try:
        parsed = urlsplit(value)
    except ValueError:
        return None
    host = (parsed.hostname or "").lower().rstrip(".")
    if (
        parsed.scheme != "https"
        or not host.endswith(".googlevideo.com")
        or host == "googlevideo.com"
        or parsed.username is not None
        or parsed.password is not None
        or parsed.port is not None
    ):
        return None

    return value


def _format_mime(value: dict[str, Any]) -> str | None:
    container = value.get("ext")
    vcodec = value.get("vcodec")
    if container in {"mp4", "m4a"}:
        return "audio/mp4" if vcodec in {None, "none"} else "video/mp4"
    if container == "webm":
        return "audio/webm" if vcodec in {None, "none"} else "video/webm"
    return None


def _positive_int(value: Any) -> int | None:
    return value if isinstance(value, int) and value > 0 else None
