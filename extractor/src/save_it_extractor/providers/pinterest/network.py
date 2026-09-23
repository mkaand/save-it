"""Anonymous Pinterest PinResource client protected by the shared pinned egress policy."""

import json
from dataclasses import dataclass
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

METADATA_HOSTS = frozenset({"www.pinterest.com"})
SHORT_REDIRECT_HOSTS = frozenset(
    {"pin.it", "api.pinterest.com", ".pinterest.com", "www.pinterest.es"}
)
ASSET_HOSTS = frozenset({"i.pinimg.com", "v1.pinimg.com"})


def is_allowed_asset_url(raw_url: str) -> bool:
    return is_allowed_url(raw_url, ASSET_HOSTS)


@dataclass(frozen=True, slots=True)
class PinterestMetadataClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def fetch_pin(self, pin_id: str) -> dict:
        data = json.dumps(
            {"options": {"field_set_key": "detailed", "id": pin_id}},
            separators=(",", ":"),
        )
        url = "https://www.pinterest.com/resource/PinResource/get/?" + urlencode({"data": data})
        payload = await self._json_request(
            url,
            METADATA_HOSTS,
            {"X-Pinterest-PWS-Handler": "www/[username].js"},
        )
        response = payload.get("resource_response")
        if not isinstance(response, dict) or not isinstance(response.get("data"), dict):
            raise ProviderError(
                "provider_response_changed",
                "Pinterest returned unsupported public Pin metadata.",
                502,
                {"provider": "pinterest"},
            )
        return response["data"]

    async def resolve_short_link(self, short_url: str) -> str:
        url = short_url
        timeout = httpx.Timeout(
            settings.pinterest_read_timeout_seconds,
            connect=settings.pinterest_connect_timeout_seconds,
        )
        pinned = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned,
            headers={"User-Agent": "Save-It-Metadata-Extractor/1"},
        ) as client:
            for hop in range(settings.pinterest_max_redirects + 1):
                remote = await _validate_redirect(url, first=hop == 0)
                if pinned is not None:
                    pinned.pin(remote)
                try:
                    response = await client.get(url)
                except httpx.TimeoutException as exc:
                    raise _error("provider_timeout", 503) from exc
                except httpx.NetworkError as exc:
                    raise _error("upstream_unavailable", 503) from exc
                if not response.is_redirect:
                    return str(response.url)
                if hop >= settings.pinterest_max_redirects:
                    raise _error("too_many_redirects", 502)
                location = response.headers.get("location")
                if not location:
                    raise _error("invalid_redirect", 502)
                url = urljoin(url, location)
        raise _error("too_many_redirects", 502)

    async def _json_request(
        self,
        url: str,
        allowed_hosts: frozenset[str],
        headers: dict[str, str],
    ) -> dict:
        timeout = httpx.Timeout(
            settings.pinterest_read_timeout_seconds,
            connect=settings.pinterest_connect_timeout_seconds,
        )
        pinned = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=timeout,
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned,
            headers={
                "Accept": "application/json",
                "User-Agent": "Save-It-Metadata-Extractor/1",
                **headers,
            },
        ) as client:
            remote = await _validate(url, allowed_hosts)
            if pinned is not None:
                pinned.pin(remote)
            try:
                async with client.stream("GET", url) as response:
                    if response.status_code == 429:
                        raise _error("rate_limited_upstream", 503)
                    if response.status_code in {401, 403}:
                        raise _error("authentication_required", 422)
                    if response.status_code in {404, 410}:
                        raise _error("post_unavailable", 422)
                    if response.status_code >= 500:
                        raise _error("upstream_unavailable", 503)
                    if (
                        response.status_code != 200
                        or content_type(response.headers.get("content-type", ""))
                        != "application/json"
                    ):
                        raise _error("provider_response_changed", 502)
                    try:
                        body = await read_limited(response, settings.pinterest_max_metadata_bytes)
                    except EgressResponseTooLarge as exc:
                        raise _error("provider_response_too_large", 502) from exc
            except httpx.TimeoutException as exc:
                raise _error("provider_timeout", 503) from exc
            except httpx.NetworkError as exc:
                raise _error("upstream_unavailable", 503) from exc
        try:
            payload = json.loads(body)
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise _error("provider_response_changed", 502) from exc
        if not isinstance(payload, dict):
            raise _error("provider_response_changed", 502)
        return payload


async def _validate(url: str, hosts: frozenset[str]) -> ResolvedRemote:
    try:
        return await resolve_allowed_url(url, hosts)
    except EgressPolicyError as exc:
        code = "upstream_unavailable" if exc.reason == "dns_unavailable" else "disallowed_redirect"
        status = 503 if exc.reason == "dns_unavailable" else 502
        raise _error(code, status) from exc


async def _validate_redirect(url: str, first: bool) -> ResolvedRemote:
    hosts = frozenset({"pin.it"}) if first else SHORT_REDIRECT_HOSTS
    return await _validate(url, hosts)


def _error(code: str, status: int) -> ProviderError:
    messages = {
        "provider_timeout": "Pinterest did not respond before the analysis deadline.",
        "upstream_unavailable": "Pinterest metadata is temporarily unavailable.",
        "rate_limited_upstream": "Pinterest is temporarily rate limiting public metadata requests.",
        "authentication_required": (
            "This Pinterest Pin requires authentication and cannot be analyzed anonymously."
        ),
        "post_unavailable": "This Pinterest Pin is unavailable or private.",
        "provider_response_changed": "Pinterest returned unsupported public Pin metadata.",
        "provider_response_too_large": "Pinterest returned more metadata than the service accepts.",
        "disallowed_redirect": "Pinterest returned an unsafe redirect.",
        "too_many_redirects": "Pinterest returned too many redirects.",
        "invalid_redirect": "Pinterest returned an invalid redirect.",
    }
    return ProviderError(code, messages[code], status, {"provider": "pinterest"})
