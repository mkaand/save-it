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

METADATA_HOSTS = frozenset({"www.instagram.com"})


def is_allowed_asset_url(raw_url: str) -> bool:
    return is_allowed_url(raw_url, {".cdninstagram.com"})


@dataclass(frozen=True, slots=True)
class InstagramMetadataClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def fetch(self, kind: str, shortcode: str) -> str:
        return await self._fetch_page(
            f"https://www.instagram.com/{kind}/{shortcode}/embed/captioned/"
        )

    async def fetch_canonical_page(self, kind: str, shortcode: str) -> str:
        return await self._fetch_page(f"https://www.instagram.com/{kind}/{shortcode}/")

    async def _fetch_page(self, url: str) -> str:
        timeout = httpx.Timeout(
            settings.instagram_read_timeout_seconds,
            connect=settings.instagram_connect_timeout_seconds,
        )

        pinned_transport = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned_transport,
            headers={
                "Accept": "text/html,application/xhtml+xml",
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)",
            },
        ) as client:
            for redirect_count in range(settings.instagram_max_redirects + 1):
                remote = await _validate_remote_url(url)
                if pinned_transport is not None:
                    pinned_transport.pin(remote)
                try:
                    async with client.stream("GET", url) as response:
                        if response.is_redirect:
                            if redirect_count >= settings.instagram_max_redirects:
                                raise ProviderError(
                                    "provider_response_changed",
                                    "Instagram returned too many redirects.",
                                    502,
                                )
                            location = response.headers.get("location")
                            if not location:
                                raise ProviderError(
                                    "provider_response_changed",
                                    "Instagram returned an invalid redirect.",
                                    502,
                                )
                            url = urljoin(url, location)
                            continue

                        if response.status_code == 429:
                            raise ProviderError(
                                "rate_limited_upstream",
                                "Instagram is temporarily rate limiting metadata requests.",
                                503,
                            )
                        if response.status_code in {401, 403}:
                            raise ProviderError(
                                "post_unavailable",
                                "The Instagram media is unavailable or requires authentication.",
                                422,
                            )
                        if response.status_code == 404:
                            raise ProviderError(
                                "post_unavailable",
                                "The Instagram media is unavailable or not public.",
                                422,
                            )
                        if response.status_code != 200:
                            raise ProviderError(
                                "upstream_unavailable",
                                "Instagram metadata is temporarily unavailable.",
                                503,
                            )

                        if "html" not in content_type(response.headers.get("content-type", "")):
                            raise ProviderError(
                                "provider_response_changed",
                                "Instagram returned an unexpected metadata response.",
                                502,
                            )

                        try:
                            body = await read_limited(
                                response, settings.instagram_max_metadata_bytes
                            )
                        except EgressResponseTooLarge as exception:
                            raise ProviderError(
                                "provider_response_too_large",
                                "Instagram returned more metadata than the service accepts.",
                                502,
                            ) from exception

                        try:
                            return body.decode("utf-8")
                        except UnicodeDecodeError as exception:
                            raise ProviderError(
                                "provider_response_changed",
                                "Instagram returned invalid metadata.",
                                502,
                            ) from exception
                except httpx.TimeoutException as exception:
                    raise ProviderError(
                        "provider_timeout",
                        "Instagram did not respond before the extraction deadline.",
                        503,
                    ) from exception
                except httpx.NetworkError as exception:
                    raise ProviderError(
                        "upstream_unavailable",
                        "Instagram metadata is temporarily unavailable.",
                        503,
                    ) from exception

        raise ProviderError("upstream_unavailable", "Instagram metadata is unavailable.", 503)


async def _validate_remote_url(raw_url: str) -> ResolvedRemote:
    try:
        return await resolve_allowed_url(raw_url, METADATA_HOSTS)
    except EgressPolicyError as exception:
        if exception.reason == "dns_unavailable":
            raise ProviderError(
                "upstream_unavailable", "Instagram metadata is unavailable.", 503
            ) from exception
        raise ProviderError(
            "disallowed_redirect", "Instagram returned an unsafe redirect.", 502
        ) from exception
