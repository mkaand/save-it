import ipaddress
import socket
from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit

import httpx

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError

METADATA_HOSTS = frozenset({"www.linkedin.com", "linkedin.com", "m.linkedin.com"})
SHORT_LINK_HOST = "lnkd.in"
AUTH_PATH_PREFIXES = ("/authwall", "/checkpoint", "/login", "/uas/login")


def is_allowed_asset_url(raw_url: str) -> bool:
    try:
        parsed = urlsplit(raw_url)
        hostname = (parsed.hostname or "").rstrip(".").lower()
        return (
            parsed.scheme == "https"
            and hostname.endswith(".licdn.com")
            and hostname != "licdn.com"
            and parsed.username is None
            and parsed.password is None
            and parsed.port is None
        )
    except ValueError:
        return False


@dataclass(frozen=True, slots=True)
class LinkedInMetadataClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def fetch(self, normalized_url: str) -> str:
        url = normalized_url
        timeout = httpx.Timeout(
            settings.linkedin_read_timeout_seconds,
            connect=settings.linkedin_connect_timeout_seconds,
        )

        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport,
            headers={
                "Accept": "text/html,application/xhtml+xml",
                "Accept-Language": "en",
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)",
            },
        ) as client:
            for redirect_count in range(settings.linkedin_max_redirects + 1):
                await _validate_remote_url(url)
                try:
                    async with client.stream("GET", url) as response:
                        if response.is_redirect:
                            if redirect_count >= settings.linkedin_max_redirects:
                                raise ProviderError(
                                    "upstream_blocked",
                                    "LinkedIn returned too many redirects for anonymous access.",
                                    503,
                                    {"provider": "linkedin"},
                                )
                            location = response.headers.get("location")
                            if not location:
                                raise ProviderError(
                                    "temporary_provider_error",
                                    "LinkedIn returned an invalid metadata redirect.",
                                    503,
                                    {"provider": "linkedin"},
                                )
                            target = urljoin(url, location)
                            if _is_auth_url(target):
                                raise ProviderError(
                                    "authentication_required",
                                    "This LinkedIn post requires signing in and "
                                    "cannot be analyzed anonymously.",
                                    422,
                                    {"provider": "linkedin"},
                                )
                            url = target
                            continue

                        if response.status_code == 429:
                            raise ProviderError(
                                "rate_limited",
                                "LinkedIn is temporarily rate limiting anonymous metadata access.",
                                503,
                                {"provider": "linkedin"},
                            )
                        if response.status_code == 401:
                            raise ProviderError(
                                "authentication_required",
                                "This LinkedIn post requires signing in and "
                                "cannot be analyzed anonymously.",
                                422,
                                {"provider": "linkedin"},
                            )
                        if response.status_code in {403, 999}:
                            raise ProviderError(
                                "upstream_blocked",
                                "LinkedIn temporarily blocked anonymous metadata "
                                "access. Please try again later.",
                                503,
                                {"provider": "linkedin"},
                            )
                        if response.status_code in {404, 410}:
                            raise ProviderError(
                                "unavailable_content",
                                "This LinkedIn post is private or unavailable.",
                                422,
                                {"provider": "linkedin"},
                            )
                        if response.status_code >= 500:
                            raise ProviderError(
                                "temporary_provider_error",
                                "LinkedIn metadata is temporarily unavailable.",
                                503,
                                {"provider": "linkedin"},
                            )
                        if response.status_code != 200:
                            raise ProviderError(
                                "unavailable_content",
                                "This LinkedIn post is private or unavailable.",
                                422,
                                {"provider": "linkedin"},
                            )

                        content_type = (
                            response.headers.get("content-type", "")
                            .split(";", 1)[0]
                            .strip()
                            .lower()
                        )
                        if content_type not in {"text/html", "application/xhtml+xml"}:
                            raise ProviderError(
                                "parsing_failed",
                                "LinkedIn returned an unsupported metadata response.",
                                502,
                                {"provider": "linkedin"},
                            )

                        body = bytearray()
                        async for chunk in response.aiter_bytes():
                            body.extend(chunk)
                            if len(body) > settings.linkedin_max_metadata_bytes:
                                raise ProviderError(
                                    "provider_response_too_large",
                                    "LinkedIn returned more metadata than the service accepts.",
                                    502,
                                    {"provider": "linkedin"},
                                )

                        try:
                            return body.decode("utf-8")
                        except UnicodeDecodeError as exception:
                            raise ProviderError(
                                "parsing_failed",
                                "LinkedIn returned invalid metadata.",
                                502,
                                {"provider": "linkedin"},
                            ) from exception
                except httpx.TimeoutException as exception:
                    raise ProviderError(
                        "provider_timeout",
                        "LinkedIn did not respond before the analysis deadline.",
                        503,
                        {"provider": "linkedin"},
                    ) from exception
                except httpx.NetworkError as exception:
                    raise ProviderError(
                        "temporary_provider_error",
                        "LinkedIn metadata is temporarily unavailable.",
                        503,
                        {"provider": "linkedin"},
                    ) from exception

        raise ProviderError(
            "temporary_provider_error",
            "LinkedIn metadata is temporarily unavailable.",
            503,
            {"provider": "linkedin"},
        )

    async def resolve_short_link(self, short_url: str) -> str:
        """Resolve only an lnkd.in redirect chain ending at a public LinkedIn host."""
        url = short_url
        timeout = httpx.Timeout(settings.linkedin_read_timeout_seconds, connect=settings.linkedin_connect_timeout_seconds)
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport,
            headers={"User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)"},
        ) as client:
            for redirect_count in range(settings.linkedin_max_redirects + 1):
                await _validate_redirect_url(url, allow_short=redirect_count == 0)
                try:
                    response = await client.get(url)
                except httpx.TimeoutException as exception:
                    raise ProviderError("provider_timeout", "LinkedIn did not respond before the analysis deadline.", 503, {"provider": "linkedin"}) from exception
                except httpx.NetworkError as exception:
                    raise ProviderError("temporary_provider_error", "LinkedIn metadata is temporarily unavailable.", 503, {"provider": "linkedin"}) from exception
                if not response.is_redirect:
                    hostname = (urlsplit(url).hostname or "").rstrip(".").lower()
                    if hostname in METADATA_HOSTS:
                        return url
                    raise ProviderError("disallowed_redirect", "LinkedIn returned an unsafe redirect.", 502, {"provider": "linkedin"})
                if redirect_count >= settings.linkedin_max_redirects:
                    raise ProviderError("upstream_blocked", "LinkedIn returned too many redirects for anonymous access.", 503, {"provider": "linkedin"})
                location = response.headers.get("location")
                if not location:
                    raise ProviderError("disallowed_redirect", "LinkedIn returned an unsafe redirect.", 502, {"provider": "linkedin"})
                url = urljoin(url, location)
                if _is_auth_url(url):
                    raise ProviderError("authentication_required", "This LinkedIn post requires signing in and cannot be analyzed anonymously.", 422, {"provider": "linkedin"})
        raise ProviderError("temporary_provider_error", "LinkedIn metadata is temporarily unavailable.", 503, {"provider": "linkedin"})


def _is_auth_url(raw_url: str) -> bool:
    path = urlsplit(raw_url).path.lower()
    return any(path.startswith(prefix) for prefix in AUTH_PATH_PREFIXES)


async def _validate_remote_url(raw_url: str) -> None:
    await _validate_redirect_url(raw_url, allow_short=False)


async def _validate_redirect_url(raw_url: str, *, allow_short: bool) -> None:
    parsed = urlsplit(raw_url)
    try:
        port = parsed.port
    except ValueError as exception:
        raise ProviderError(
            "disallowed_redirect",
            "LinkedIn returned an unsafe redirect.",
            502,
            {"provider": "linkedin"},
        ) from exception

    hostname = (parsed.hostname or "").rstrip(".").lower()
    if (
        parsed.scheme != "https"
        or hostname not in (METADATA_HOSTS | ({SHORT_LINK_HOST} if allow_short else set()))
        or parsed.username is not None
        or parsed.password is not None
        or port is not None
        or _is_auth_url(raw_url)
    ):
        raise ProviderError(
            "disallowed_redirect",
            "LinkedIn returned an unsafe redirect.",
            502,
            {"provider": "linkedin"},
        )

    try:
        records = socket.getaddrinfo(
            hostname,
            443,
            type=socket.SOCK_STREAM,
        )
    except socket.gaierror as exception:
        raise ProviderError(
            "temporary_provider_error",
            "LinkedIn metadata is temporarily unavailable.",
            503,
            {"provider": "linkedin"},
        ) from exception

    if not records:
        raise ProviderError(
            "temporary_provider_error",
            "LinkedIn metadata is temporarily unavailable.",
            503,
            {"provider": "linkedin"},
        )

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
                "LinkedIn metadata resolved to a disallowed network address.",
                502,
                {"provider": "linkedin"},
            )
