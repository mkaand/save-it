"""Anonymous TikTok canonical-page client using the shared pinned egress policy."""

from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit

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

CANONICAL_HOSTS = frozenset({"www.tiktok.com"})
SHORT_HOSTS = frozenset({"vm.tiktok.com", "vt.tiktok.com"})
# Observed in TikTok's canonical hydration state for direct public MP4 and covers.
ASSET_HOSTS = frozenset(
    {
        "v16-webapp-prime.tiktok.com",
        "v19-webapp-prime.tiktok.com",
        "p16-common-sign.tiktokcdn-eu.com",
    }
)


def is_allowed_asset_url(raw_url: str) -> bool:
    return is_allowed_url(raw_url, ASSET_HOSTS)


@dataclass(frozen=True, slots=True)
class TikTokMetadataClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def fetch_page(self, canonical_url: str) -> str:
        return await self._request(canonical_url, CANONICAL_HOSTS, canonical=True)

    async def resolve_short_link(self, short_url: str) -> str:
        url = short_url
        timeout = httpx.Timeout(
            settings.tiktok_read_timeout_seconds, connect=settings.tiktok_connect_timeout_seconds
        )
        pinned = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned,
            headers={
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)"
            },
        ) as client:
            for hop in range(settings.tiktok_max_redirects + 1):
                remote = await _validate(url, SHORT_HOSTS if hop == 0 else CANONICAL_HOSTS)
                if pinned is not None:
                    pinned.pin(remote)
                try:
                    response = await client.get(url)
                except httpx.TimeoutException as exc:
                    raise _error("provider_timeout", 503) from exc
                except httpx.NetworkError as exc:
                    raise _error("upstream_unavailable", 503) from exc
                if not response.is_redirect:
                    if (urlsplit(url).hostname or "").rstrip(".").lower() == "www.tiktok.com":
                        return url
                    raise _error("disallowed_redirect", 502)
                if hop >= settings.tiktok_max_redirects:
                    raise _error("too_many_redirects", 502)
                location = response.headers.get("location")
                if not location:
                    raise _error("invalid_redirect", 502)
                url = urljoin(url, location)
        raise _error("too_many_redirects", 502)

    async def _request(
        self, initial_url: str, initial_hosts: frozenset[str], *, canonical: bool
    ) -> str:
        url = initial_url
        timeout = httpx.Timeout(
            settings.tiktok_read_timeout_seconds,
            connect=settings.tiktok_connect_timeout_seconds,
        )
        pinned = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned,
            headers={
                "Accept": "text/html,application/xhtml+xml",
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)",
            },
        ) as client:
            for hop in range(settings.tiktok_max_redirects + 1):
                hosts = CANONICAL_HOSTS if canonical or hop > 0 else initial_hosts
                remote = await _validate(url, hosts)
                if pinned is not None:
                    pinned.pin(remote)
                try:
                    async with client.stream("GET", url) as response:
                        if response.is_redirect:
                            if hop >= settings.tiktok_max_redirects:
                                raise _error("too_many_redirects", 502)
                            location = response.headers.get("location")
                            if not location:
                                raise _error("invalid_redirect", 502)
                            url = urljoin(url, location)
                            canonical = True
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
                            body = await read_limited(response, settings.tiktok_max_metadata_bytes)
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


async def _validate(url: str, hosts: frozenset[str]) -> ResolvedRemote:
    try:
        return await resolve_allowed_url(url, hosts)
    except EgressPolicyError as exc:
        raise _error(
            "upstream_unavailable" if exc.reason == "dns_unavailable" else "disallowed_redirect",
            503 if exc.reason == "dns_unavailable" else 502,
        ) from exc


def _error(code: str, status: int) -> ProviderError:
    messages = {
        "provider_timeout": "TikTok did not respond before the analysis deadline.",
        "upstream_unavailable": "TikTok metadata is temporarily unavailable.",
        "rate_limited_upstream": "TikTok is temporarily rate limiting public metadata requests.",
        "authentication_required": (
            "This TikTok post requires authentication and cannot be analyzed anonymously."
        ),
        "post_unavailable": "This TikTok post is unavailable or private.",
        "provider_response_changed": "TikTok returned unsupported public metadata.",
        "provider_response_too_large": "TikTok returned more metadata than the service accepts.",
        "disallowed_redirect": "TikTok returned an unsafe redirect.",
        "too_many_redirects": "TikTok returned too many redirects.",
        "invalid_redirect": "TikTok returned an invalid redirect.",
    }
    return ProviderError(code, messages[code], status, {"provider": "tiktok"})
