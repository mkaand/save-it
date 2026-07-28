import asyncio
import re
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

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
