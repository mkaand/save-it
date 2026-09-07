import asyncio
import re
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlsplit

from yt_dlp import YoutubeDL
from yt_dlp.utils import DownloadError

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError

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

        return await asyncio.to_thread(self._fetch_sync, canonical_url)

    async def resolve_format(self, canonical_url: str, format_id: str) -> dict[str, Any]:
        payload = await self.fetch(canonical_url)
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
                if isinstance(item, dict) and item.get("format_id") == format_id
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
        }

    def _fetch_sync(self, canonical_url: str) -> dict[str, Any]:
        options: dict[str, Any] = {
            "cachedir": False,
            "cookiefile": None,
            "download": False,
            "extract_flat": False,
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
            # The default web clients may expose formats whose Google Video Server
            # requests require a Proof of Origin token. We do not use credentials
            # or PO tokens, so prefer the anonymous progressive Android client.
            "extractor_args": {"youtube": {"player_client": ["android"]}},
            "writesubtitles": False,
            "writeautomaticsub": False,
            "writethumbnail": False,
            "js_runtimes": {},
            "remote_components": set(),
        }

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
    if container == "mp4":
        return "audio/mp4" if vcodec in {None, "none"} else "video/mp4"
    if container == "webm":
        return "audio/webm" if vcodec in {None, "none"} else "video/webm"
    return None


def _positive_int(value: Any) -> int | None:
    return value if isinstance(value, int) and value > 0 else None
