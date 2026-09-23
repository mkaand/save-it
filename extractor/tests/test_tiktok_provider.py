import asyncio
import json
from dataclasses import dataclass

import pytest

from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.tiktok.adapter import TikTokProviderAdapter
from save_it_extractor.providers.tiktok.parser import parse_tiktok_video

VIDEO = "https://v16-webapp-prime.tiktok.com/obj/example.mp4?token=redacted"
COVER = "https://p16-common-sign.tiktokcdn-eu.com/obj/example-cover.jpeg?token=redacted"
ORIGIN_COVER = "https://p16-common-sign.tiktokcdn-eu.com/obj/origin-cover.jpeg?token=redacted"


def page(
    video_id: str = "6718335390845095173",
    username: str = "scout2015",
    bitrate_info: list[dict] | None = None,
) -> str:
    state = {
        "__DEFAULT_SCOPE__": {
            "webapp.video-detail": {
                "itemInfo": {
                    "itemStruct": {
                        "id": video_id,
                        "desc": "Public video",
                        "author": {"uniqueId": username, "nickname": "Scout"},
                        "video": {
                            "playAddr": VIDEO,
                            "cover": COVER,
                            "originCover": ORIGIN_COVER,
                            "dynamicCover": "https://p16-common-sign.tiktokcdn-eu.com/obj/dynamic-cover.jpeg",
                            "width": 576,
                            "height": 1024,
                            "duration": 1234,
                            "bitrateInfo": bitrate_info
                            or [
                                {
                                    "PlayAddr": {
                                        "UrlList": [VIDEO],
                                        "Width": 576,
                                        "Height": 1024,
                                        "DataSize": 2_953_029,
                                    },
                                    "Bitrate": 1000,
                                }
                            ],
                        },
                    },
                },
            },
        },
    }
    return (
        '<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__" type="application/json">'
        + json.dumps(state)
        + "</script>"
    )


def test_canonical_and_short_urls_normalize_without_tracking() -> None:
    canonical = classify_url(
        "https://www.tiktok.com/@scout2015/video/6718335390845095173?share_app_id=1"
    )
    short = classify_url("https://vm.tiktok.com/ZMexample/?tracking=value")
    assert canonical.provider is Provider.TIKTOK
    assert canonical.variant == "video"
    assert canonical.normalized_url == "https://www.tiktok.com/@scout2015/video/6718335390845095173"
    assert short.variant == "short"
    assert short.normalized_url == "https://vm.tiktok.com/ZMexample"


@pytest.mark.parametrize(
    "url",
    [
        "https://www.tiktok.com/@scout2015",
        "https://www.tiktok.com/tag/cats",
        "https://www.tiktok.com/music/example-1",
        "https://www.tiktok.com/live/example",
        "https://www.tiktok.com/@scout2015/video/not-an-id",
    ],
)
def test_non_video_urls_are_rejected(url: str) -> None:
    with pytest.raises(UrlValidationError) as exception:
        classify_url(url)
    assert exception.value.code == "invalid_tiktok_video_url"


def test_parser_uses_identity_checked_structured_video_and_not_social_metadata() -> None:
    metadata, assets, media_type = parse_tiktok_video(page(), "6718335390845095173", "scout2015")
    assert media_type == "video"
    assert metadata["media_count"] == 1
    assert assets[0]["url"] == VIDEO
    assert assets[0]["thumbnail_url"] == ORIGIN_COVER
    assert assets[0]["mime_type"] == "video/mp4"


def test_parser_deterministically_prefers_the_highest_structured_mp4_variant() -> None:
    source = page(
        bitrate_info=[
            {
                "PlayAddr": {
                    "UrlList": ["https://v16-webapp-prime.tiktok.com/obj/low.mp4"],
                    "Width": 360,
                    "Height": 640,
                },
                "Bitrate": 500_000,
            },
            {
                "PlayAddr": {
                    "UrlList": ["https://v19-webapp-prime.tiktok.com/obj/high.mp4"],
                    "Width": 720,
                    "Height": 1280,
                },
                "Bitrate": 1_500_000,
            },
        ]
    )
    _, assets, _ = parse_tiktok_video(source, "6718335390845095173", "scout2015")
    assert assets[0]["width"] == 720
    assert assets[0]["height"] == 1280
    assert len(assets[0]["variants"]) == 3
    assert assets[0]["variants"][0]["quality_label"] == "720×1280 · 1.5 Mbps"


def test_parser_collapses_equivalent_signed_urls_and_retains_meaningful_quality() -> None:
    source = page(
        bitrate_info=[
            {
                "PlayAddr": {
                    "UrlList": [VIDEO + "&signature=first"],
                    "Width": 576,
                    "Height": 1024,
                    "DataSize": 2_953_029,
                },
                "Bitrate": 2_240_963,
            },
            {
                "PlayAddr": {
                    "UrlList": [
                        VIDEO.replace("v16-webapp-prime", "v19-webapp-prime") + "&signature=second"
                    ],
                    "Width": 576,
                    "Height": 1024,
                },
                "Bitrate": 1_500_000,
            },
        ]
    )
    _, assets, _ = parse_tiktok_video(source, "6718335390845095173", "scout2015")
    variants = assets[0]["variants"]
    assert len(variants) == 1
    assert variants[0]["quality_label"] == "576×1024 · 2.2 Mbps"
    assert variants[0]["filesize"] == 2_953_029
    assert variants[0]["is_preferred"] is True


def test_parser_uses_bitrate_as_the_label_when_dimensions_are_missing() -> None:
    source = page(
        bitrate_info=[
            {
                "PlayAddr": {
                    "UrlList": ["https://v19-webapp-prime.tiktok.com/obj/bitrate-only.mp4"]
                },
                "Bitrate": 1_250_000,
            }
        ]
    )
    _, assets, _ = parse_tiktok_video(source, "6718335390845095173", "scout2015")
    assert "1.2 Mbps" in [variant["quality_label"] for variant in assets[0]["variants"]]


def test_parser_prefers_static_origin_cover_and_never_uses_dynamic_cover() -> None:
    _, assets, _ = parse_tiktok_video(page(), "6718335390845095173", "scout2015")
    assert assets[0]["thumbnail_url"] == ORIGIN_COVER

    without_origin = page().replace(f'"originCover": "{ORIGIN_COVER}", ', "")
    _, assets, _ = parse_tiktok_video(without_origin, "6718335390845095173", "scout2015")
    assert assets[0]["thumbnail_url"] == COVER


def test_parser_rejects_unsafe_video_hosts_and_missing_video() -> None:
    unsafe = page().replace(VIDEO, "https://evil.example/video.mp4")
    with pytest.raises(ProviderError) as exception:
        parse_tiktok_video(unsafe, "6718335390845095173", "scout2015")
    assert exception.value.code == "no_media"


@pytest.mark.parametrize(
    "html,video_id,username",
    [
        (page(video_id="1"), "6718335390845095173", "scout2015"),
        (page(username="other"), "6718335390845095173", "scout2015"),
        ("<html></html>", "6718335390845095173", "scout2015"),
    ],
)
def test_parser_rejects_malformed_or_identity_mismatched_state(
    html: str, video_id: str, username: str
) -> None:
    with pytest.raises(ProviderError) as exception:
        parse_tiktok_video(html, video_id, username)
    assert exception.value.code == "provider_response_changed"


@dataclass
class FakeClient:
    async def fetch_page(self, _: str) -> str:
        return page()

    async def resolve_short_link(self, _: str) -> str:
        return "https://www.tiktok.com/@scout2015/video/6718335390845095173"


def test_adapter_returns_beta_ready_contract_for_shortlink() -> None:
    result = asyncio.run(
        TikTokProviderAdapter(client=FakeClient()).extract(
            classify_url("https://vm.tiktok.com/ZMexample"), "tiktok-test"
        )
    )
    assert result.provider is Provider.TIKTOK
    assert result.status == "ready"
    assert result.media_type == "video"
    assert result.maturity == "beta"
