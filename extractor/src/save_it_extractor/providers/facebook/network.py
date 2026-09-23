"""Pinned anonymous metadata client for Facebook's public video/Reel pages."""

from dataclasses import dataclass
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

CANONICAL_HOSTS = frozenset({"www.facebook.com"})
# Facebook's observed public video and static-poster delivery hosts are regional
# children of xx.fbcdn.net. This is intentionally narrower than .fbcdn.net.
ASSET_HOSTS = frozenset({".xx.fbcdn.net"})
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

    async def fetch_page(self, canonical_url: str) -> str:
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
                    return body.decode("utf-8")
                except UnicodeDecodeError as exc:
                    raise _error("provider_response_changed", 502) from exc
        raise _error("too_many_redirects", 502)


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
