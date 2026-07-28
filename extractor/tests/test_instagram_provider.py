import asyncio
import json
import socket
from dataclasses import replace
from typing import Any

import httpx
import pytest

from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.instagram import network
from save_it_extractor.providers.instagram.adapter import InstagramProviderAdapter
from save_it_extractor.providers.instagram.network import (
    InstagramMetadataClient,
    is_allowed_asset_url,
)
from save_it_extractor.providers.instagram.parser import parse_instagram_embed

IMAGE = "https://scontent-lhr8-1.cdninstagram.com/v/t51.2885-15/image.jpg"
VIDEO = "https://scontent-lhr8-1.cdninstagram.com/o1/v/t16/video.mp4"


def media_node(*, video: bool = False, suffix: str = "") -> dict[str, Any]:
    return {
        "__typename": "GraphVideo" if video else "GraphImage",
        "shortcode": f"Code123{suffix}",
        "is_video": video,
        "display_url": IMAGE.replace("image", f"image{suffix}"),
        "video_url": VIDEO.replace("video", f"video{suffix}") if video else None,
        "video_duration": 12.5 if video else None,
        "dimensions": {"width": 1080, "height": 1350},
        "accessibility_caption": "Safe preview",
    }


def document(root: dict[str, Any]) -> str:
    payload = {"context": {"type": root.get("__typename")}, "gql_data": {"shortcode_media": root}}
    return f'<script>window.__data={{"contextJSON":{json.dumps(json.dumps(payload))}}};</script>'


def root_media(*, kind: str = "image", children: list[dict] | None = None) -> dict:
    root = media_node(video=kind == "video")
    root.update(
        {
            "shortcode": "Code123",
            "taken_at_timestamp": 1785230400,
            "owner": {"username": "save.it_demo", "full_name": "Save It Demo"},
            "edge_media_to_caption": {
                "edges": [{"node": {"text": "Public caption &amp; metadata"}}]
            },
        }
    )
    if children is not None:
        root["edge_sidecar_to_children"] = {"edges": [{"node": child} for child in children]}
    return root


@pytest.mark.parametrize(
    ("url", "normalized", "variant"),
    [
        (
            "https://instagram.com/p/Code123/?utm_source=share#fragment",
            "https://www.instagram.com/p/Code123/",
            "post",
        ),
        (
            "https://www.instagram.com/reel/Reel_456/",
            "https://www.instagram.com/reel/Reel_456/",
            "reel",
        ),
    ],
)
def test_instagram_media_urls_are_canonicalized(url: str, normalized: str, variant: str) -> None:
    context = classify_url(url)
    assert context.provider is Provider.INSTAGRAM
    assert context.normalized_url == normalized
    assert context.variant == variant


@pytest.mark.parametrize(
    "url",
    [
        "https://instagram.com/save_it",
        "https://instagram.com/stories/save_it/123/",
        "https://instagram.com/live/123/",
        "https://instagram.com/explore/",
        "https://instagram.com/p/x/",
        "https://instagram.com/p/Code123/extra",
        "https://instagram.com.evil.example/p/Code123/",
    ],
)
def test_non_media_instagram_urls_are_rejected(url: str) -> None:
    with pytest.raises(UrlValidationError):
        classify_url(url)


@pytest.mark.parametrize(
    ("root", "kind", "expected_type", "count"),
    [
        (root_media(), "post", "image", 1),
        (root_media(kind="video"), "reel", "reel", 1),
        (
            root_media(children=[media_node(suffix="A"), media_node(suffix="B")]),
            "post",
            "carousel",
            2,
        ),
        (
            root_media(children=[media_node(suffix="A"), media_node(video=True, suffix="B")]),
            "post",
            "carousel",
            2,
        ),
    ],
)
def test_parser_supports_post_reel_image_and_carousel(
    root: dict, kind: str, expected_type: str, count: int
) -> None:
    metadata, assets, media_type = parse_instagram_embed(document(root), "Code123", kind)

    assert media_type == expected_type
    assert metadata["caption"] == "Public caption & metadata"
    assert metadata["author_handle"] == "save.it_demo"
    assert metadata["media_count"] == count
    assert [asset["order"] for asset in assets] == list(range(1, count + 1))
    assert all(asset["url"].startswith("https://") for asset in assets)
    assert not any("binary" in asset or "download" in asset for asset in assets)


def test_parser_rejects_missing_media_mismatch_and_unsafe_assets() -> None:
    missing = root_media()
    missing["display_url"] = "https://cdninstagram.com.evil.example/image.jpg"
    with pytest.raises(ProviderError, match="extractable"):
        parse_instagram_embed(document(missing), "Code123", "post")

    with pytest.raises(ProviderError) as mismatch:
        parse_instagram_embed(document(root_media()), "Other123", "post")
    assert mismatch.value.code == "provider_response_changed"


def test_asset_host_matching_is_exact_suffix_and_https_only() -> None:
    assert is_allowed_asset_url(IMAGE)
    assert not is_allowed_asset_url("https://cdninstagram.com/image.jpg")
    assert not is_allowed_asset_url("https://cdninstagram.com.evil.example/image.jpg")
    assert not is_allowed_asset_url("http://scontent.cdninstagram.com/image.jpg")
    assert not is_allowed_asset_url("https://scontent.cdninstagram.com:8443/image.jpg")


def public_dns(*_args, **_kwargs):
    return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("157.240.1.1", 443))]


def test_network_client_uses_canonical_path_and_ignores_proxy_env(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)
    monkeypatch.setenv("HTTPS_PROXY", "http://127.0.0.1:9")
    seen: httpx.Request | None = None

    async def handler(request: httpx.Request) -> httpx.Response:
        nonlocal seen
        seen = request
        return httpx.Response(
            200,
            headers={"content-type": "text/html; charset=utf-8"},
            text=document(root_media()),
        )

    page = asyncio.run(InstagramMetadataClient(httpx.MockTransport(handler)).fetch("p", "Code123"))
    assert "contextJSON" in page
    assert seen is not None
    assert seen.url == httpx.URL("https://www.instagram.com/p/Code123/embed/captioned/")


def test_redirect_private_dns_timeout_and_size_are_controlled(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)

    async def redirect(_: httpx.Request) -> httpx.Response:
        return httpx.Response(302, headers={"location": "https://127.0.0.1/private"})

    with pytest.raises(ProviderError) as unsafe:
        asyncio.run(InstagramMetadataClient(httpx.MockTransport(redirect)).fetch("p", "Code123"))
    assert unsafe.value.code == "disallowed_redirect"

    monkeypatch.setattr(
        network,
        "settings",
        replace(network.settings, instagram_max_metadata_bytes=32),
    )

    async def oversized(_: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            headers={"content-type": "text/html"},
            content=b"a" * 64,
        )

    with pytest.raises(ProviderError) as too_large:
        asyncio.run(InstagramMetadataClient(httpx.MockTransport(oversized)).fetch("p", "Code123"))
    assert too_large.value.code == "provider_response_too_large"

    async def timeout(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("private detail", request=request)

    with pytest.raises(ProviderError) as timed_out:
        asyncio.run(InstagramMetadataClient(httpx.MockTransport(timeout)).fetch("p", "Code123"))
    assert timed_out.value.code == "provider_timeout"
    assert "private detail" not in timed_out.value.message


def test_adapter_returns_ready_contract_without_binary_fetch() -> None:
    class FakeClient:
        async def fetch(self, kind: str, shortcode: str) -> str:
            assert (kind, shortcode) == ("p", "Code123")
            return document(root_media(children=[media_node(suffix="A"), media_node(suffix="B")]))

    result = asyncio.run(
        InstagramProviderAdapter(client=FakeClient()).extract(
            classify_url("https://instagram.com/p/Code123/"),
            "request-instagram",
        )
    )
    assert result.status == "ready"
    assert result.media_type == "carousel"
    assert result.capabilities == ["metadata", "media_assets", "multiple_assets"]
    assert result.normalized_url == "https://www.instagram.com/p/Code123/"


@pytest.mark.live
def test_optional_live_public_instagram_embed() -> None:
    import os

    if os.getenv("SAVE_IT_RUN_INSTAGRAM_LIVE_TESTS") != "1":
        pytest.skip("Set SAVE_IT_RUN_INSTAGRAM_LIVE_TESTS=1 to run provider checks.")

    result = asyncio.run(
        InstagramProviderAdapter().extract(
            classify_url("https://www.instagram.com/p/fA9uwTtkSN/"),
            "live-instagram",
        )
    )
    assert result.status == "ready"
    assert result.assets
