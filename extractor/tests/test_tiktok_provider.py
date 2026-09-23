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
                            "width": 576,
                            "height": 1024,
                            "duration": 1234,
                            "bitrateInfo": bitrate_info
                            or [
                                {
                                    "PlayAddr": {"UrlList": [VIDEO]},
                                    "width": 576,
                                    "height": 1024,
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
    assert assets[0]["thumbnail_url"] == COVER
    assert assets[0]["mime_type"] == "video/mp4"


def test_parser_deterministically_prefers_the_highest_structured_mp4_variant() -> None:
    source = page(
        bitrate_info=[
            {
                "PlayAddr": {"UrlList": ["https://v16-webapp-prime.tiktok.com/obj/low.mp4"]},
                "width": 360,
                "height": 640,
                "Bitrate": 500,
            },
            {
                "PlayAddr": {"UrlList": ["https://v19-webapp-prime.tiktok.com/obj/high.mp4"]},
                "width": 720,
                "height": 1280,
                "Bitrate": 1500,
            },
        ]
    )
    _, assets, _ = parse_tiktok_video(source, "6718335390845095173", "scout2015")
    assert assets[0]["width"] == 720
    assert assets[0]["height"] == 1280
    assert len(assets[0]["variants"]) == 3


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
