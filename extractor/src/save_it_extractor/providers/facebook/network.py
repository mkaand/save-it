"""Pinned anonymous metadata client for Facebook's public video/Reel pages."""

from dataclasses import dataclass
from struct import unpack
from urllib.parse import urljoin

import httpx

from save_it_extractor.config import settings
from save_it_extractor.providers.egress import (
    EgressPolicyError,
    EgressResponseTooLarge,
    PinnedAsyncHTTPTransport,
    ResolvedRemote,
    content_type,
    is_allowed_url,
    read_limited,
    resolve_allowed_url,
)
from save_it_extractor.providers.errors import ProviderError

CANONICAL_HOSTS = frozenset({
    "facebook.com",
    "www.facebook.com",
    "m.facebook.com",
    "web.facebook.com",
    "fb.watch",
})
# Facebook's observed public video and static-poster delivery hosts are regional
# children of xx.fbcdn.net. This is intentionally narrower than .fbcdn.net.
ASSET_HOSTS = frozenset({".xx.fbcdn.net", ".fna.fbcdn.net"})
COBALT_HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36"
    ),
    "Accept": (
        "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8"
    ),
    "Accept-Language": "en-US,en;q=0.5",
    "Sec-Fetch-Mode": "navigate",
    "Sec-Fetch-Site": "none",
}


def is_allowed_asset_url(raw_url: str) -> bool:
    return is_allowed_url(raw_url, ASSET_HOSTS)


@dataclass(frozen=True, slots=True)
class FacebookMetadataClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def fetch_page(self, canonical_url: str) -> "FacebookPage":
        url = canonical_url
        timeout = httpx.Timeout(
            settings.facebook_read_timeout_seconds,
            connect=settings.facebook_connect_timeout_seconds,
        )
        pinned = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned,
            headers=COBALT_HEADERS,
        ) as client:
            for hop in range(settings.facebook_max_redirects + 1):
                remote = await _validate(url)
                if pinned is not None:
                    pinned.pin(remote)
                try:
                    async with client.stream("GET", url) as response:
                        if response.is_redirect:
                            if hop >= settings.facebook_max_redirects:
                                raise _error("too_many_redirects", 502)
                            location = response.headers.get("location")
                            if not location:
                                raise _error("invalid_redirect", 502)
                            url = urljoin(url, location)
                            continue
                        if response.status_code == 429:
                            raise _error("rate_limited_upstream", 503)
                        if response.status_code in {401, 403}:
                            raise _error("authentication_required", 422)
                        if response.status_code in {404, 410}:
                            raise _error("post_unavailable", 422)
                        if response.status_code >= 500:
                            raise _error("upstream_unavailable", 503)
                        if response.status_code != 200 or content_type(
                            response.headers.get("content-type", "")
                        ) not in {"text/html", "application/xhtml+xml"}:
                            raise _error("provider_response_changed", 502)
                        try:
                            body = await read_limited(
                                response, settings.facebook_max_metadata_bytes
                            )
                        except EgressResponseTooLarge as exc:
                            raise _error("provider_response_too_large", 502) from exc
                except httpx.TimeoutException as exc:
                    raise _error("provider_timeout", 503) from exc
                except httpx.NetworkError as exc:
                    raise _error("upstream_unavailable", 503) from exc
                try:
                    return FacebookPage(url=url, html=body.decode("utf-8"))
                except UnicodeDecodeError as exc:
                    raise _error("provider_response_changed", 502) from exc
        raise _error("too_many_redirects", 502)

    async def probe_mp4(self, url: str) -> dict[str, int | bool] | None:
        """Read a bounded MP4 prefix to obtain rendition-local track metadata.

        This uses the same pinned HTTPS policy as page retrieval and never
        downloads a media object or exposes its signed URL. A missing faststart
        moov atom is intentionally treated as unknown rather than guessed.
        """
        try:
            remote = await resolve_allowed_url(url, ASSET_HOSTS)
        except EgressPolicyError:
            return None
        transport = PinnedAsyncHTTPTransport()
        transport.pin(remote)
        timeout = httpx.Timeout(settings.facebook_read_timeout_seconds, connect=settings.facebook_connect_timeout_seconds)
        try:
            async with httpx.AsyncClient(timeout=timeout, trust_env=False, transport=transport) as client:
                async with client.stream("GET", url, headers={"Range": "bytes=0-524287", "Accept": "video/mp4"}) as response:
                    if response.status_code not in {200, 206} or content_type(response.headers.get("content-type", "")) != "video/mp4":
                        return None
                    body = await read_limited(response, 524_288)
        except (httpx.TimeoutException, httpx.NetworkError, EgressResponseTooLarge):
            return None
        return _mp4_tracks(body)


@dataclass(frozen=True, slots=True)
class FacebookPage:
    """One fresh, in-memory anonymous Facebook document session.

    HTTPX retains only Set-Cookie values issued while walking the validated
    Facebook page redirect chain. The client is closed immediately afterwards;
    neither application request cookies nor CDN cookies are forwarded or stored.
    """

    url: str
    html: str


def _mp4_tracks(data: bytes) -> dict[str, int | bool] | None:
    moov = next((payload for name, payload in _boxes(data) if name == b"moov"), None)
    if moov is None:
        return None
    video_width = video_height = None
    has_audio = False
    for name, track in _boxes(moov):
        if name != b"trak":
            continue
        handler = _track_handler(track)
        if handler == b"soun":
            has_audio = True
        elif handler == b"vide":
            dimensions = _track_dimensions(track)
            if dimensions is not None:
                video_width, video_height = dimensions
    if video_width is None or video_height is None:
        return None
    return {"width": video_width, "height": video_height, "has_audio": has_audio}


def _boxes(data: bytes):
    offset = 0
    while offset + 8 <= len(data):
        size = unpack(">I", data[offset:offset + 4])[0]
        name = data[offset + 4:offset + 8]
        header = 8
        if size == 1:
            if offset + 16 > len(data):
                return
            size = unpack(">Q", data[offset + 8:offset + 16])[0]
            header = 16
        if size < header or offset + size > len(data):
            return
        yield name, data[offset + header:offset + size]
        offset += size


def _track_handler(track: bytes) -> bytes | None:
    for name, payload in _boxes(track):
        if name != b"mdia":
            continue
        for child, value in _boxes(payload):
            if child == b"hdlr" and len(value) >= 12:
                return value[8:12]
    return None


def _track_dimensions(track: bytes) -> tuple[int, int] | None:
    for name, payload in _boxes(track):
        if name == b"tkhd" and len(payload) >= 8:
            width = unpack(">I", payload[-8:-4])[0] >> 16
            height = unpack(">I", payload[-4:])[0] >> 16
            if width > 0 and height > 0:
                return width, height
    return None


async def _validate(url: str) -> ResolvedRemote:
    try:
        return await resolve_allowed_url(url, CANONICAL_HOSTS)
    except EgressPolicyError as exc:
        raise _error(
            "upstream_unavailable" if exc.reason == "dns_unavailable" else "disallowed_redirect",
            503 if exc.reason == "dns_unavailable" else 502,
        ) from exc


def _error(code: str, status: int) -> ProviderError:
    messages = {
        "provider_timeout": "Facebook did not respond before the analysis deadline.",
        "upstream_unavailable": "Facebook metadata is temporarily unavailable.",
        "rate_limited_upstream": "Facebook is temporarily rate limiting public metadata requests.",
        "authentication_required": (
            "This Facebook post requires authentication and cannot be analyzed anonymously."
        ),
        "post_unavailable": "This Facebook post is unavailable or private.",
        "provider_response_changed": "Facebook returned unsupported public metadata.",
        "provider_response_too_large": "Facebook returned more metadata than the service accepts.",
        "disallowed_redirect": "Facebook returned an unsafe redirect.",
        "too_many_redirects": "Facebook returned too many redirects.",
        "invalid_redirect": "Facebook returned an invalid redirect.",
    }
    return ProviderError(code, messages[code], status, {"provider": "facebook"})
