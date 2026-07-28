import asyncio
import ipaddress
import socket
from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit

import httpx

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError

METADATA_HOSTS = frozenset({"www.instagram.com"})


def is_allowed_asset_url(raw_url: str) -> bool:
    try:
        parsed = urlsplit(raw_url)
        hostname = (parsed.hostname or "").rstrip(".").lower()
        return (
            parsed.scheme == "https"
            and hostname.endswith(".cdninstagram.com")
            and hostname != "cdninstagram.com"
            and parsed.username is None
            and parsed.password is None
            and parsed.port is None
        )
    except ValueError:
        return False


@dataclass(frozen=True, slots=True)
class InstagramMetadataClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def fetch(self, kind: str, shortcode: str) -> str:
        url = f"https://www.instagram.com/{kind}/{shortcode}/embed/captioned/"
        timeout = httpx.Timeout(
            settings.instagram_read_timeout_seconds,
            connect=settings.instagram_connect_timeout_seconds,
        )

        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport,
            headers={
                "Accept": "text/html,application/xhtml+xml",
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)",
            },
        ) as client:
            for redirect_count in range(settings.instagram_max_redirects + 1):
                await _validate_remote_url(url)
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

                        content_type = response.headers.get("content-type", "").lower()
                        if "html" not in content_type:
                            raise ProviderError(
                                "provider_response_changed",
                                "Instagram returned an unexpected metadata response.",
                                502,
                            )

                        body = bytearray()
                        async for chunk in response.aiter_bytes():
                            body.extend(chunk)
                            if len(body) > settings.instagram_max_metadata_bytes:
                                raise ProviderError(
                                    "provider_response_too_large",
                                    "Instagram returned more metadata than the service accepts.",
                                    502,
                                )

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


async def _validate_remote_url(raw_url: str) -> None:
    parsed = urlsplit(raw_url)
    try:
        port = parsed.port
    except ValueError as exception:
        raise ProviderError(
            "disallowed_redirect", "Instagram returned an unsafe redirect.", 502
        ) from exception

    hostname = (parsed.hostname or "").rstrip(".").lower()
    if (
        parsed.scheme != "https"
        or hostname not in METADATA_HOSTS
        or parsed.username is not None
        or parsed.password is not None
        or port is not None
    ):
        raise ProviderError("disallowed_redirect", "Instagram returned an unsafe redirect.", 502)

    try:
        records = await asyncio.to_thread(
            socket.getaddrinfo,
            hostname,
            443,
            type=socket.SOCK_STREAM,
        )
    except socket.gaierror as exception:
        raise ProviderError(
            "upstream_unavailable", "Instagram metadata is unavailable.", 503
        ) from exception

    if not records:
        raise ProviderError("upstream_unavailable", "Instagram metadata is unavailable.", 503)

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
                "Instagram metadata resolved to a disallowed network address.",
                502,
            )
