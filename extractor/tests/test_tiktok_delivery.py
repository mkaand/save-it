import asyncio
import json

import httpx
import pytest

from save_it_extractor.providers.egress import ResolvedRemote
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.tiktok.delivery import TikTokMediaDeliveryClient

SOURCE = "https://www.tiktok.com/@scout2015/video/6718335390845095173"
MEDIA = "https://v16-webapp-prime.tiktok.com/obj/video.mp4?token=sensitive"


def page() -> bytes:
    state = {
        "__DEFAULT_SCOPE__": {
            "webapp.video-detail": {
                "itemInfo": {
                    "itemStruct": {
                        "id": "6718335390845095173",
                        "author": {"uniqueId": "scout2015"},
                        "video": {
                            "playAddr": MEDIA.replace("sensitive", "fresh"),
                            "cover": "https://p16-common-sign.tiktokcdn-eu.com/obj/poster.jpeg",
                        },
                    }
                }
            }
        }
    }
    return (
        '<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__" type="application/json">'
        + json.dumps(state)
        + "</script>"
    ).encode()


class OneByteStream(httpx.AsyncByteStream):
    async def __aiter__(self):  # type: ignore[no-untyped-def]
        yield b"x"


def test_relay_uses_only_the_anonymous_chain_cookie_and_preserves_range(monkeypatch) -> None:
    async def resolve(url: str, _hosts: frozenset[str]) -> ResolvedRemote:
        host = httpx.URL(url).host
        return ResolvedRemote(host, ("203.0.113.1",))

    monkeypatch.setattr("save_it_extractor.providers.tiktok.delivery.resolve_allowed_url", resolve)
    requests: list[httpx.Request] = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        if request.url.host == "www.tiktok.com":
            return httpx.Response(
                200,
                headers=[
                    ("content-type", "text/html"),
                    (
                        "set-cookie",
                        "tt_chain_token=anonymous-chain; Domain=.tiktok.com; "
                        "Path=/; Secure; HttpOnly",
                    ),
                    (
                        "set-cookie",
                        "msToken=unneeded; Domain=www.tiktok.com; Path=/; Secure",
                    ),
                ],
                content=page(),
                request=request,
            )
        assert request.headers["range"] == "bytes=0-0"
        assert request.headers["referer"] == SOURCE
        assert request.headers["cookie"] == "tt_chain_token=anonymous-chain"
        return httpx.Response(
            206,
            headers={
                "content-type": "video/mp4",
                "content-length": "1",
                "content-range": "bytes 0-0/100",
                "accept-ranges": "bytes",
            },
            stream=OneByteStream(),
            request=request,
        )

    async def run() -> tuple[int, dict[str, str], bytes]:
        relay = await TikTokMediaDeliveryClient(httpx.MockTransport(handler)).open(
            SOURCE, MEDIA, "bytes=0-0"
        )
        body = b"".join([chunk async for chunk in relay.body()])
        return relay.status_code, relay.headers, body

    status, headers, body = asyncio.run(run())
    assert status == 206
    assert headers["content-range"] == "bytes 0-0/100"
    assert body == b"x"
    assert len(requests) == 2


def test_relay_rejects_a_noncanonical_source_before_network() -> None:
    def handler(_: httpx.Request) -> httpx.Response:
        raise AssertionError("network must not be called")

    with pytest.raises(ProviderError, match="invalid_source") as caught:
        asyncio.run(
            TikTokMediaDeliveryClient(httpx.MockTransport(handler)).open(
                SOURCE + "?tracking=1", MEDIA, None
            )
        )

    assert caught.value.code == "invalid_source"


def test_relay_fails_closed_without_the_anonymous_chain_cookie(monkeypatch) -> None:
    async def resolve(url: str, _hosts: frozenset[str]) -> ResolvedRemote:
        host = httpx.URL(url).host
        return ResolvedRemote(host, ("203.0.113.1",))

    monkeypatch.setattr("save_it_extractor.providers.tiktok.delivery.resolve_allowed_url", resolve)

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            headers={"content-type": "text/html"},
            content=page(),
            request=request,
        )

    with pytest.raises(ProviderError) as caught:
        asyncio.run(
            TikTokMediaDeliveryClient(httpx.MockTransport(handler)).open(SOURCE, MEDIA, None)
        )

    assert caught.value.code == "session_unavailable"


def test_relay_rejects_an_unsafe_media_host(monkeypatch) -> None:
    async def resolve(url: str, hosts: frozenset[str]) -> ResolvedRemote:
        host = httpx.URL(url).host
        if host not in hosts:
            from save_it_extractor.providers.egress import EgressPolicyError

            raise EgressPolicyError("unsafe_url")
        return ResolvedRemote(host, ("203.0.113.1",))

    monkeypatch.setattr("save_it_extractor.providers.tiktok.delivery.resolve_allowed_url", resolve)

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            headers={
                "content-type": "text/html",
                "set-cookie": (
                    "tt_chain_token=anonymous-chain; Domain=.tiktok.com; Path=/; Secure; HttpOnly"
                ),
            },
            content=b"",
            request=request,
        )

    with pytest.raises(ProviderError) as caught:
        asyncio.run(
            TikTokMediaDeliveryClient(httpx.MockTransport(handler)).open(
                SOURCE, "https://example.com/video.mp4", None
            )
        )

    assert caught.value.code == "unsafe_media_url"


def test_session_refresh_maps_a_rotating_structured_poster_to_the_same_post() -> None:
    client = TikTokMediaDeliveryClient()
    refreshed = "https://p16-common-sign.tiktokcdn-eu.com/obj/refreshed.jpeg?token=new"

    assert (
        client._matching_session_media(  # noqa: SLF001 - focused security contract test
            [{"url": MEDIA, "thumbnail_url": refreshed, "variants": []}],
            "https://p16-common-sign.tiktokcdn-eu.com/obj/expired.jpeg?token=old",
        )
        == refreshed
    )
    assert (
        client._matching_session_media(  # noqa: SLF001 - focused security contract test
            [{"url": MEDIA, "thumbnail_url": refreshed, "variants": []}],
            "https://v16-webapp-prime.tiktok.com/obj/different-video.mp4",
        )
        is None
    )
