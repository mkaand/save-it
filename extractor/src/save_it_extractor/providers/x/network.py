import asyncio
import ipaddress
import json
import socket
from dataclasses import dataclass
from hashlib import sha256
from urllib.parse import urlencode, urljoin, urlsplit

import httpx

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError

METADATA_HOSTS = frozenset({"cdn.syndication.twimg.com"})
ASSET_HOSTS = frozenset({"pbs.twimg.com", "video.twimg.com"})


def is_allowed_asset_url(raw_url: str) -> bool:
    try:
        parsed = urlsplit(raw_url)
        return (
            parsed.scheme == "https"
            and parsed.hostname is not None
            and parsed.hostname.lower() in ASSET_HOSTS
            and parsed.username is None
            and parsed.password is None
            and parsed.port is None
        )
    except ValueError:
        return False


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

        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport,
            headers={
                "Accept": "application/json",
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)",
            },
        ) as client:
            for redirect_count in range(settings.x_max_redirects + 1):
                await _validate_remote_url(url)
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

                        content_type = response.headers.get("content-type", "").lower()
                        if "json" not in content_type:
                            raise ProviderError(
                                "provider_response_changed",
                                "X returned an unexpected metadata response.",
                                502,
                            )

                        body = bytearray()
                        async for chunk in response.aiter_bytes():
                            body.extend(chunk)
                            if len(body) > settings.x_max_metadata_bytes:
                                raise ProviderError(
                                    "provider_response_too_large",
                                    "X returned more metadata than the service accepts.",
                                    502,
                                )

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


async def _validate_remote_url(raw_url: str) -> None:
    parsed = urlsplit(raw_url)
    try:
        port = parsed.port
    except ValueError as exception:
        raise ProviderError(
            "disallowed_redirect", "X returned an unsafe redirect.", 502
        ) from exception

    hostname = (parsed.hostname or "").rstrip(".").lower()
    if (
        parsed.scheme != "https"
        or hostname not in METADATA_HOSTS
        or parsed.username is not None
        or parsed.password is not None
        or port is not None
    ):
        raise ProviderError("disallowed_redirect", "X returned an unsafe redirect.", 502)

    try:
        records = await asyncio.to_thread(
            socket.getaddrinfo,
            hostname,
            443,
            type=socket.SOCK_STREAM,
        )
    except socket.gaierror as exception:
        raise ProviderError(
            "upstream_unavailable", "X metadata is unavailable.", 503
        ) from exception

    if not records:
        raise ProviderError("upstream_unavailable", "X metadata is unavailable.", 503)

    for record in records:
        address = ipaddress.ip_address(record[4][0])
        if (
            not address.is_global
            or address.is_loopback
            or address.is_private
            or address.is_link_local
            or address.is_multicast
            or address.is_reserved
            or address.is_unspecified
        ):
            raise ProviderError(
                "disallowed_redirect",
                "X metadata resolved to a disallowed network address.",
                502,
            )
