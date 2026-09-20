import json
from dataclasses import dataclass
from hashlib import sha256
from urllib.parse import urlencode, urljoin

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

METADATA_HOSTS = frozenset({"cdn.syndication.twimg.com"})
ASSET_HOSTS = frozenset({"pbs.twimg.com", "video.twimg.com"})


def is_allowed_asset_url(raw_url: str) -> bool:
    return is_allowed_url(raw_url, ASSET_HOSTS)


@dataclass(frozen=True, slots=True)
class XMetadataClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def fetch(self, status_id: str) -> dict:
        discriminator = sha256(f"save-it-x-v1:{status_id}".encode()).hexdigest()[:12]
        query = urlencode({"id": status_id, "lang": "en", "token": discriminator})
        url = f"https://cdn.syndication.twimg.com/tweet-result?{query}"
        timeout = httpx.Timeout(
            settings.x_read_timeout_seconds,
            connect=settings.x_connect_timeout_seconds,
        )

        pinned_transport = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned_transport,
            headers={
                "Accept": "application/json",
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)",
            },
        ) as client:
            for redirect_count in range(settings.x_max_redirects + 1):
                remote = await _validate_remote_url(url)
                if pinned_transport is not None:
                    pinned_transport.pin(remote)
                try:
                    async with client.stream("GET", url) as response:
                        if response.is_redirect:
                            if redirect_count >= settings.x_max_redirects:
                                raise ProviderError(
                                    "provider_response_changed",
                                    "The provider returned too many redirects.",
                                    502,
                                )
                            location = response.headers.get("location")
                            if not location:
                                raise ProviderError(
                                    "provider_response_changed",
                                    "The provider returned an invalid redirect.",
                                    502,
                                )
                            url = urljoin(url, location)
                            continue

                        if response.status_code == 429:
                            raise ProviderError(
                                "rate_limited_upstream",
                                "X is temporarily rate limiting metadata requests.",
                                503,
                            )
                        if response.status_code in {401, 403}:
                            raise ProviderError(
                                "provider_blocked",
                                "X did not allow this public metadata request.",
                                503,
                            )
                        if response.status_code == 404:
                            raise ProviderError(
                                "post_unavailable",
                                "The X post is unavailable.",
                                422,
                            )
                        if response.status_code != 200:
                            raise ProviderError(
                                "upstream_unavailable",
                                "X metadata is temporarily unavailable.",
                                503,
                            )

                        if "json" not in content_type(response.headers.get("content-type", "")):
                            raise ProviderError(
                                "provider_response_changed",
                                "X returned an unexpected metadata response.",
                                502,
                            )

                        try:
                            body = await read_limited(response, settings.x_max_metadata_bytes)
                        except EgressResponseTooLarge as exception:
                            raise ProviderError(
                                "provider_response_too_large",
                                "X returned more metadata than the service accepts.",
                                502,
                            ) from exception

                        try:
                            payload = json.loads(body)
                        except (UnicodeDecodeError, json.JSONDecodeError) as exception:
                            raise ProviderError(
                                "provider_response_changed",
                                "X returned invalid metadata.",
                                502,
                            ) from exception

                        if not isinstance(payload, dict) or not payload:
                            raise ProviderError(
                                "post_unavailable",
                                "The X post is unavailable or not public.",
                                422,
                            )
                        return payload
                except httpx.TimeoutException as exception:
                    raise ProviderError(
                        "provider_timeout",
                        "X did not respond before the extraction deadline.",
                        503,
                    ) from exception
                except httpx.NetworkError as exception:
                    raise ProviderError(
                        "upstream_unavailable",
                        "X metadata is temporarily unavailable.",
                        503,
                    ) from exception

        raise ProviderError("upstream_unavailable", "X metadata is unavailable.", 503)


async def _validate_remote_url(raw_url: str) -> ResolvedRemote:
    try:
        return await resolve_allowed_url(raw_url, METADATA_HOSTS)
    except EgressPolicyError as exception:
        if exception.reason == "dns_unavailable":
            raise ProviderError(
                "upstream_unavailable", "X metadata is unavailable.", 503
            ) from exception
        raise ProviderError(
            "disallowed_redirect", "X returned an unsafe redirect.", 502
        ) from exception
