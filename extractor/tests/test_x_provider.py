import asyncio
import socket
from typing import Any

import httpx
import pytest

from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.x.adapter import XProviderAdapter
from save_it_extractor.providers.x.network import XMetadataClient, is_allowed_asset_url
from save_it_extractor.providers.x.parser import parse_x_payload


def photo(url: str, width: int = 1200, height: int = 800) -> dict[str, Any]:
    return {
        "type": "photo",
        "media_url_https": url,
        "original_info": {"width": width, "height": height},
    }


def video(kind: str = "video") -> dict[str, Any]:
    return {
        "type": kind,
        "media_url_https": "https://pbs.twimg.com/ext_tw_video_thumb/poster.jpg",
        "original_info": {"width": 1280, "height": 720},
        "video_info": {
            "duration_millis": 12345,
            "variants": [
                {
                    "content_type": "application/x-mpegURL",
                    "url": "https://video.twimg.com/path/playlist.m3u8",
                },
                {
                    "content_type": "video/mp4",
                    "bitrate": 256000,
                    "url": "https://video.twimg.com/path/320x180/low.mp4",
                },
                {
                    "content_type": "video/mp4",
                    "bitrate": 2176000,
                    "url": "https://video.twimg.com/path/1280x720/high.mp4",
                },
                {
                    "content_type": "video/mp4",
                    "bitrate": 2176000,
                    "url": "https://video.twimg.com/path/1280x720/high.mp4",
                },
            ],
        },
    }


def payload(media: list[dict], **overrides: Any) -> dict[str, Any]:
    result = {
        "id_str": "123",
        "text": "A public X post &amp; safe text",
        "created_at": "2026-07-28T12:00:00.000Z",
        "user": {"name": "Example Author", "screen_name": "example"},
        "mediaDetails": media,
    }
    result.update(overrides)
    return result


@pytest.mark.parametrize(
    "url",
    [
        "https://x.com/example/status/123",
        "https://www.x.com/example/status/123?ref=share#fragment",
        "https://twitter.com/example/status/123/photo/1",
        "https://www.twitter.com/example/status/123/video/2",
        "https://mobile.twitter.com/example/status/123/",
    ],
)
def test_x_status_urls_are_canonicalized(url: str) -> None:
    context = classify_url(url)
    assert context.provider is Provider.X
    assert context.normalized_url == "https://x.com/example/status/123"


@pytest.mark.parametrize(
    "url",
    [
        "https://x.com/example",
        "https://x.com/explore",
        "https://x.com/search?q=media",
        "https://x.com/intent/post",
        "https://x.com/example/status/not-numeric",
        "https://x.com/example/status/123/unknown/1",
        "https://x.com.evil.example/example/status/123",
    ],
)
def test_non_post_x_urls_are_rejected(url: str) -> None:
    with pytest.raises(UrlValidationError):
        classify_url(url)


@pytest.mark.parametrize(
    ("media", "expected_type"),
    [
        ([photo("https://pbs.twimg.com/media/one.jpg?format=jpg&name=small")], "image"),
        ([video()], "video"),
        ([video("animated_gif")], "animated_gif"),
        (
            [
                photo("https://pbs.twimg.com/media/one.jpg?format=jpg"),
                photo("https://pbs.twimg.com/media/two.png?format=png"),
            ],
            "carousel",
        ),
        (
            [video(), photo("https://pbs.twimg.com/media/one.jpg?format=jpg")],
            "mixed_media",
        ),
    ],
)
def test_parser_supports_x_media_shapes(media: list[dict], expected_type: str) -> None:
    metadata, assets, media_type = parse_x_payload(payload(media), "123")

    assert media_type == expected_type
    assert metadata["media_count"] == len(assets)
    assert [asset["order"] for asset in assets] == list(range(1, len(assets) + 1))
    assert metadata["text"] == "A public X post & safe text"
    assert all(asset["url"].startswith("https://") for asset in assets)


def test_video_variants_are_deduplicated_and_preferred_deterministically() -> None:
    _, assets, _ = parse_x_payload(payload([video()]), "123")
    variants = assets[0]["variants"]

    assert len(variants) == 3
    assert variants[0]["url"].endswith("/1280x720/high.mp4")
    assert variants[0]["quality_label"] == "1280×720"
    assert variants[0]["is_preferred"] is True
    assert sum(variant["is_preferred"] for variant in variants) == 1
    assert not any("size" in variant or "codec" in variant for variant in variants)


def test_duplicate_assets_are_removed_and_quote_media_is_ignored() -> None:
    item = photo("https://pbs.twimg.com/media/one.jpg?format=jpg")
    metadata, assets, _ = parse_x_payload(
        payload([item, item], quoted_tweet={"mediaDetails": [video()]}),
        "123",
    )

    assert metadata["media_count"] == 1
    assert len(assets) == 1
    assert assets[0]["type"] == "image"


def test_no_media_is_controlled() -> None:
    with pytest.raises(ProviderError) as exception:
        parse_x_payload(payload([]), "123")

    assert exception.value.code == "no_media"
    assert exception.value.status_code == 422


def test_malformed_or_mismatched_payload_is_rejected() -> None:
    with pytest.raises(ProviderError) as exception:
        parse_x_payload(payload([video()], id_str="999"), "123")

    assert exception.value.code == "provider_response_changed"


def test_only_allowlisted_asset_hosts_are_accepted() -> None:
    assert is_allowed_asset_url("https://pbs.twimg.com/media/example.jpg")
    assert is_allowed_asset_url("https://video.twimg.com/path/video.mp4")
    assert not is_allowed_asset_url("https://pbs.twimg.com.evil.example/media/example.jpg")
    assert not is_allowed_asset_url("http://pbs.twimg.com/media/example.jpg")
    assert not is_allowed_asset_url("https://pbs.twimg.com:8443/media/example.jpg")


def public_dns(*_args, **_kwargs):
    return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("104.244.42.1", 443))]


def test_network_client_ignores_proxy_environment_and_limits_response(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)
    monkeypatch.setenv("HTTPS_PROXY", "http://127.0.0.1:9")
    seen_request: httpx.Request | None = None

    async def handler(request: httpx.Request) -> httpx.Response:
        nonlocal seen_request
        seen_request = request
        return httpx.Response(
            200,
            headers={"content-type": "application/json"},
            json=payload([video()]),
        )

    result = asyncio.run(XMetadataClient(httpx.MockTransport(handler)).fetch("123"))
    assert result["id_str"] == "123"
    assert seen_request is not None
    assert seen_request.url.host == "cdn.syndication.twimg.com"
    assert seen_request.url.scheme == "https"
    assert "token" in seen_request.url.params


def test_redirect_is_revalidated_and_private_dns_is_rejected(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    calls = 0

    def rebinding_dns(*_args, **_kwargs):
        nonlocal calls
        calls += 1
        address = "104.244.42.1" if calls == 1 else "127.0.0.1"
        return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (address, 443))]

    monkeypatch.setattr(socket, "getaddrinfo", rebinding_dns)

    async def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(302, headers={"location": str(request.url)})

    with pytest.raises(ProviderError) as exception:
        asyncio.run(XMetadataClient(httpx.MockTransport(handler)).fetch("123"))

    assert exception.value.code == "disallowed_redirect"


def test_redirect_to_arbitrary_host_is_rejected(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)

    async def handler(_: httpx.Request) -> httpx.Response:
        return httpx.Response(302, headers={"location": "https://example.com/private"})

    with pytest.raises(ProviderError) as exception:
        asyncio.run(XMetadataClient(httpx.MockTransport(handler)).fetch("123"))

    assert exception.value.code == "disallowed_redirect"


def test_metadata_response_size_is_limited(monkeypatch: pytest.MonkeyPatch) -> None:
    from dataclasses import replace

    from save_it_extractor.providers.x import network

    monkeypatch.setattr(socket, "getaddrinfo", public_dns)
    monkeypatch.setattr(network, "settings", replace(network.settings, x_max_metadata_bytes=32))

    async def handler(_: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            headers={"content-type": "application/json"},
            content=b'{"padding":"' + (b"a" * 64) + b'"}',
        )

    with pytest.raises(ProviderError) as exception:
        asyncio.run(XMetadataClient(httpx.MockTransport(handler)).fetch("123"))

    assert exception.value.code == "provider_response_too_large"


def test_network_timeout_is_mapped_without_provider_details(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)

    async def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("sensitive timeout detail", request=request)

    with pytest.raises(ProviderError) as exception:
        asyncio.run(XMetadataClient(httpx.MockTransport(handler)).fetch("123"))

    assert exception.value.code == "provider_timeout"
    assert "sensitive" not in exception.value.message


def test_adapter_returns_ready_contract_without_downloading_media() -> None:
    class FakeClient:
        async def fetch(self, status_id: str) -> dict:
            assert status_id == "123"
            return payload([video(), photo("https://pbs.twimg.com/media/one.jpg?format=jpg")])

    adapter = XProviderAdapter(client=FakeClient())
    result = asyncio.run(
        adapter.extract(classify_url("https://twitter.com/example/status/123"), "request-1")
    )

    assert result.status == "ready"
    assert result.media_type == "mixed_media"
    assert result.normalized_url == "https://x.com/example/status/123"
    assert result.capabilities == [
        "metadata",
        "media_assets",
        "video_variants",
        "multiple_assets",
    ]
    assert not any("binary" in asset for asset in result.assets)


@pytest.mark.live
def test_optional_live_public_x_post() -> None:
    import os

    if os.getenv("SAVE_IT_RUN_X_LIVE_TESTS") != "1":
        pytest.skip("Set SAVE_IT_RUN_X_LIVE_TESTS=1 to run provider network checks.")

    result = asyncio.run(
        XProviderAdapter().extract(
            classify_url("https://x.com/BeastieMcTest/status/999022126043672578"),
            "live-test",
        )
    )
    assert result.status == "ready"
    assert result.assets
