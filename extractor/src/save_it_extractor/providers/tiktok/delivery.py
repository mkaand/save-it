"""Pinned, per-request anonymous TikTok media relay."""

import re
from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit

import httpx

from save_it_extractor.config import settings
from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.egress import (
    EgressPolicyError,
    EgressResponseTooLarge,
    PinnedAsyncHTTPTransport,
    content_type,
    read_limited,
    resolve_allowed_url,
)
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.tiktok.network import ASSET_HOSTS, CANONICAL_HOSTS
from save_it_extractor.providers.tiktok.parser import parse_tiktok_video

RANGE_PATTERN = re.compile(r"^bytes=(?:[0-9]+-[0-9]*|-[0-9]+)$")
MEDIA_TYPES = frozenset({"video/mp4", "image/jpeg", "image/png", "image/webp"})


@dataclass(slots=True)
class TikTokMediaResponse:
    response: httpx.Response
    client: httpx.AsyncClient
    maximum_bytes: int

    @property
    def status_code(self) -> int:
        return self.response.status_code

    @property
    def headers(self) -> dict[str, str]:
        allowed = {"content-type", "content-length", "content-range", "accept-ranges"}
        headers = {}
        for name, value in self.response.headers.items():
            if name.lower() in allowed:
                headers[name] = value
        return headers

    async def body(self):  # type: ignore[no-untyped-def]
        received = 0
        try:
            async for chunk in self.response.aiter_raw():
                received += len(chunk)
                if received > self.maximum_bytes:
                    raise _error("media_too_large", 413)
                yield chunk
        finally:
            await self.response.aclose()
            await self.client.aclose()


@dataclass(frozen=True, slots=True)
class TikTokMediaDeliveryClient:
    transport: httpx.AsyncBaseTransport | None = None

    async def open(
        self,
        source_page_url: str,
        media_url: str,
        range_header: str | None,
    ) -> TikTokMediaResponse:
        canonical = self._canonical_source(source_page_url)
        if range_header is not None and RANGE_PATTERN.fullmatch(range_header) is None:
            raise _error("invalid_range", 416)

        await self._validate(media_url, ASSET_HOSTS)
        chain_token, session_media_url = await self._anonymous_session(canonical, media_url)
        cookies = httpx.Cookies()
        cookies.set("tt_chain_token", chain_token, domain=".tiktok.com", path="/")
        pinned = PinnedAsyncHTTPTransport() if self.transport is None else None
        client = httpx.AsyncClient(
            timeout=httpx.Timeout(
                settings.tiktok_read_timeout_seconds,
                connect=settings.tiktok_connect_timeout_seconds,
            ),
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned,
            cookies=cookies,
            headers={
                "Accept": "*/*",
                "Accept-Encoding": "identity",
                "Referer": canonical,
                "User-Agent": "Save-It-Media-Delivery/1 (+https://github.com/mkaand/save-it)",
            },
        )
        url = session_media_url
        try:
            for hop in range(settings.tiktok_max_redirects + 1):
                remote = await self._validate(url, ASSET_HOSTS)
                if pinned is not None:
                    pinned.pin(remote)
                headers = {"Range": range_header} if range_header is not None else None
                request = client.build_request("GET", url, headers=headers)
                response = await client.send(request, stream=True)
                if response.is_redirect:
                    if hop >= settings.tiktok_max_redirects:
                        await response.aclose()
                        raise _error("too_many_redirects", 502)
                    location = response.headers.get("location")
                    await response.aclose()
                    if not location:
                        raise _error("invalid_redirect", 502)
                    url = urljoin(url, location)
                    continue
                self._validate_response(response, range_header)
                return TikTokMediaResponse(
                    response=response,
                    client=client,
                    maximum_bytes=settings.tiktok_max_media_bytes,
                )
        except httpx.TimeoutException as exception:
            await client.aclose()
            raise _error("provider_timeout", 503) from exception
        except httpx.NetworkError as exception:
            await client.aclose()
            raise _error("upstream_unavailable", 503) from exception
        except Exception:
            await client.aclose()
            raise
        await client.aclose()
        raise _error("too_many_redirects", 502)

    def _canonical_source(self, source_page_url: str) -> str:
        try:
            context = classify_url(source_page_url)
        except UrlValidationError as exception:
            raise _error("invalid_source", 422) from exception
        if (
            context.provider is not Provider.TIKTOK
            or context.variant != "video"
            or context.normalized_url != source_page_url
        ):
            raise _error("invalid_source", 422)
        return context.normalized_url

    async def _anonymous_session(self, canonical: str, requested_media: str) -> tuple[str, str]:
        pinned = PinnedAsyncHTTPTransport() if self.transport is None else None
        async with httpx.AsyncClient(
            timeout=httpx.Timeout(
                settings.tiktok_read_timeout_seconds,
                connect=settings.tiktok_connect_timeout_seconds,
            ),
            trust_env=False,
            follow_redirects=False,
            transport=self.transport or pinned,
            headers={
                "Accept": "text/html,application/xhtml+xml",
                "Accept-Encoding": "identity",
                "User-Agent": "Save-It-Metadata-Extractor/1 (+https://github.com/mkaand/save-it)",
            },
        ) as client:
            remote = await self._validate(canonical, CANONICAL_HOSTS)
            if pinned is not None:
                pinned.pin(remote)
            try:
                response = await client.send(client.build_request("GET", canonical), stream=True)
            except httpx.TimeoutException as exception:
                raise _error("provider_timeout", 503) from exception
            except httpx.NetworkError as exception:
                raise _error("upstream_unavailable", 503) from exception
            try:
                if response.status_code != 200 or content_type(
                    response.headers.get("content-type", "")
                ) not in {"text/html", "application/xhtml+xml"}:
                    raise _error("session_unavailable", 502)
                try:
                    body = await read_limited(response, settings.tiktok_max_metadata_bytes)
                except EgressResponseTooLarge as exception:
                    raise _error("session_unavailable", 502) from exception
                try:
                    html = body.decode("utf-8")
                except UnicodeDecodeError as exception:
                    raise _error("session_unavailable", 502) from exception
                match = re.fullmatch(
                    r"/@([A-Za-z0-9_.]{1,64})/video/([0-9]{10,30})",
                    urlsplit(canonical).path,
                )
                if match is None:
                    raise _error("invalid_source", 422)
                username, video_id = match.groups()
                _, assets, _ = parse_tiktok_video(html, video_id, username)
                session_media = self._matching_session_media(assets, requested_media)
                if session_media is None:
                    raise _error("session_media_changed", 410)
                for cookie in client.cookies.jar:
                    value = cookie.value
                    if (
                        cookie.name == "tt_chain_token"
                        and cookie.domain.lstrip(".").lower() == "tiktok.com"
                        and cookie.path == "/"
                        and cookie.secure
                        and value
                        and len(value) <= 2048
                    ):
                        return value, session_media
            finally:
                await response.aclose()
        raise _error("session_unavailable", 502)

    def _matching_session_media(
        self,
        assets: list[dict[str, object]],
        requested_media: str,
    ) -> str | None:
        requested_identity = _media_identity(requested_media)
        for asset in assets:
            candidates = [asset.get("url"), asset.get("thumbnail_url")]
            variants = asset.get("variants")
            if isinstance(variants, list):
                for variant in variants:
                    if isinstance(variant, dict):
                        candidates.append(variant.get("url"))
            for candidate in candidates:
                if isinstance(candidate, str) and _media_identity(candidate) == requested_identity:
                    return candidate
        requested_host = requested_identity[0]
        if requested_host == "p16-common-sign.tiktokcdn-eu.com":
            for asset in assets:
                thumbnail = asset.get("thumbnail_url")
                if isinstance(thumbnail, str) and _media_identity(thumbnail)[0] == requested_host:
                    return thumbnail
        return None

    async def _validate(self, url: str, hosts: frozenset[str]):
        try:
            return await resolve_allowed_url(url, hosts)
        except EgressPolicyError as exception:
            raise _error("unsafe_media_url", 502) from exception

    def _validate_response(self, response: httpx.Response, range_header: str | None) -> None:
        if response.status_code not in {200, 206, 416}:
            raise _error("upstream_unavailable", 502)
        if response.status_code == 416:
            return
        if content_type(response.headers.get("content-type", "")) not in MEDIA_TYPES:
            raise _error("unsupported_media_type", 422)
        length = _positive_header(response.headers.get("content-length"))
        total = _content_range_total(response.headers.get("content-range"))
        if range_header is not None and response.status_code != 206:
            raise _error("invalid_upstream_range", 502)
        if response.status_code == 206 and total is None:
            raise _error("invalid_upstream_range", 502)
        if length is None and total is None:
            raise _error("media_size_unknown", 422)
        declared_size = max(value for value in (length, total) if value is not None)
        if declared_size > settings.tiktok_max_media_bytes:
            raise _error("media_too_large", 413)


def _positive_header(value: str | None) -> int | None:
    return int(value) if value is not None and value.isdigit() else None


def _content_range_total(value: str | None) -> int | None:
    if value is None:
        return None
    match = re.fullmatch(r"bytes (?:[0-9]+-[0-9]+|\*)/([0-9]+)", value)
    return int(match.group(1)) if match else None


def _error(code: str, status: int) -> ProviderError:
    messages = {
        "invalid_range": "Only one byte range may be requested at a time.",
        "invalid_source": "The TikTok media session source is invalid.",
        "session_unavailable": "TikTok anonymous media delivery is temporarily unavailable.",
        "session_media_changed": "This TikTok media link has expired. Analyze the post again.",
        "unsafe_media_url": "TikTok returned an unsafe media location.",
        "too_many_redirects": "TikTok media returned too many redirects.",
        "invalid_redirect": "TikTok media returned an invalid redirect.",
        "upstream_unavailable": "TikTok media is temporarily unavailable.",
        "unsupported_media_type": "TikTok returned an unsupported media type.",
        "invalid_upstream_range": "TikTok returned an invalid byte range.",
        "media_size_unknown": "TikTok did not provide a safe media size.",
        "media_too_large": "TikTok media exceeds the download size limit.",
        "provider_timeout": "TikTok media did not respond before the delivery deadline.",
    }
    return ProviderError(code, messages[code], status, {"provider": "tiktok"})


def _media_identity(url: str) -> tuple[str, str]:
    parsed = urlsplit(url)
    host = (parsed.hostname or "").rstrip(".").lower()
    media_class = (
        "video"
        if host
        in {
            "v16-webapp-prime.tiktok.com",
            "v19-webapp-prime.tiktok.com",
        }
        else host
    )
    return (media_class, parsed.path)
