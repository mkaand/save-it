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

METADATA_HOSTS = frozenset({"www.linkedin.com", "linkedin.com", "m.linkedin.com"})
SHORT_LINK_HOST = "lnkd.in"
AUTH_PATH_PREFIXES = ("/authwall", "/checkpoint", "/login", "/uas/login")


def is_allowed_asset_url(raw_url: str) -> bool:
    return is_allowed_url(raw_url, {".licdn.com"})


@dataclass(frozen=True, slots=True)
class LinkedInMetadataClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def fetch(self, normalized_url: str) -> str:
        url = normalized_url
        timeout = httpx.Timeout(
            settings.linkedin_read_timeout_seconds,
            connect=settings.linkedin_connect_timeout_seconds,
        )

        pinned_transport = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned_transport,
            headers={
                "Accept": "text/html,application/xhtml+xml",
                "Accept-Language": "en",
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)",
            },
        ) as client:
            for redirect_count in range(settings.linkedin_max_redirects + 1):
                remote = await _validate_remote_url(url)
                if pinned_transport is not None:
                    pinned_transport.pin(remote)
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

                        if content_type(response.headers.get("content-type", "")) not in {
                            "text/html",
                            "application/xhtml+xml",
                        }:
                            raise ProviderError(
                                "parsing_failed",
                                "LinkedIn returned an unsupported metadata response.",
                                502,
                                {"provider": "linkedin"},
                            )

                        try:
                            body = await read_limited(
                                response, settings.linkedin_max_metadata_bytes
                            )
                        except EgressResponseTooLarge as exception:
                            raise ProviderError(
                                "provider_response_too_large",
                                "LinkedIn returned more metadata than the service accepts.",
                                502,
                                {"provider": "linkedin"},
                            ) from exception

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
        timeout = httpx.Timeout(
            settings.linkedin_read_timeout_seconds,
            connect=settings.linkedin_connect_timeout_seconds,
        )
        pinned_transport = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned_transport,
            headers={
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)"
            },
        ) as client:
            for redirect_count in range(settings.linkedin_max_redirects + 1):
                remote = await _validate_redirect_url(url, allow_short=redirect_count == 0)
                if pinned_transport is not None:
                    pinned_transport.pin(remote)
                try:
                    response = await client.get(url)
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
                if not response.is_redirect:
                    hostname = (urlsplit(url).hostname or "").rstrip(".").lower()
                    if hostname in METADATA_HOSTS:
                        return url
                    raise ProviderError(
                        "disallowed_redirect",
                        "LinkedIn returned an unsafe redirect.",
                        502,
                        {"provider": "linkedin"},
                    )
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
                        "disallowed_redirect",
                        "LinkedIn returned an unsafe redirect.",
                        502,
                        {"provider": "linkedin"},
                    )
                url = urljoin(url, location)
                if _is_auth_url(url):
                    raise ProviderError(
                        "authentication_required",
                        "This LinkedIn post requires signing in and cannot be analyzed "
                        "anonymously.",
                        422,
                        {"provider": "linkedin"},
                    )
        raise ProviderError(
            "temporary_provider_error",
            "LinkedIn metadata is temporarily unavailable.",
            503,
            {"provider": "linkedin"},
        )


def _is_auth_url(raw_url: str) -> bool:
    path = urlsplit(raw_url).path.lower()
    return any(path.startswith(prefix) for prefix in AUTH_PATH_PREFIXES)


async def _validate_remote_url(raw_url: str) -> ResolvedRemote:
    return await _validate_redirect_url(raw_url, allow_short=False)


async def _validate_redirect_url(raw_url: str, *, allow_short: bool) -> ResolvedRemote:
    if _is_auth_url(raw_url):
        raise ProviderError(
            "disallowed_redirect",
            "LinkedIn returned an unsafe redirect.",
            502,
            {"provider": "linkedin"},
        )

    allowed_hosts = METADATA_HOSTS | ({SHORT_LINK_HOST} if allow_short else set())
    try:
        return await resolve_allowed_url(raw_url, allowed_hosts)
    except EgressPolicyError as exception:
        if exception.reason == "dns_unavailable":
            raise ProviderError(
                "temporary_provider_error",
                "LinkedIn metadata is temporarily unavailable.",
                503,
                {"provider": "linkedin"},
            ) from exception
        raise ProviderError(
            "disallowed_redirect",
            "LinkedIn returned an unsafe redirect.",
            502,
            {"provider": "linkedin"},
        ) from exception
