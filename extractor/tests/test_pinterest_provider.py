import asyncio
from dataclasses import dataclass

import pytest

from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.pinterest.adapter import PinterestProviderAdapter
from save_it_extractor.providers.pinterest.parser import parse_pinterest_pin

IMAGE_LOW = "https://i.pinimg.com/736x/example.jpg"
IMAGE_ORIGINAL = "https://i.pinimg.com/originals/example.jpg"
POSTER = "https://i.pinimg.com/originals/poster.jpg"
VIDEO_720 = "https://v1.pinimg.com/videos/example-720.mp4"
VIDEO_1080 = "https://v1.pinimg.com/videos/example-1080.mp4"


def payload(pin_id: str = "615233999110252228", *, video: bool = False) -> dict:
    return {
        "id": pin_id,
        "type": "pin",
        "title": "Public Pin",
        "description": "Structured description",
        "pinner": {"full_name": "Public author", "username": "public_author"},
        "images": {
            "736x": {"url": IMAGE_LOW, "width": 736, "height": 490},
            "orig": {"url": IMAGE_ORIGINAL, "width": 2400, "height": 1600},
        },
        "videos": {
            "video_list": {
                "V_720P": {"url": VIDEO_720, "width": 720, "height": 1280},
                "V_1080P": {"url": VIDEO_1080, "width": 1080, "height": 1920},
            }
        }
        if video
        else None,
    }


def test_canonical_and_short_urls_normalize_without_tracking() -> None:
    canonical = classify_url("https://in.pinterest.com/pin/615233999110252228/?utm_source=test")
    short = classify_url("https://pin.it/5MxsDY0?tracking=value")
    assert canonical.provider is Provider.PINTEREST
    assert canonical.variant == "pin"
    assert canonical.normalized_url == "https://www.pinterest.com/pin/615233999110252228/"
    assert short.variant == "short"
    assert short.normalized_url == "https://pin.it/5MxsDY0"


@pytest.mark.parametrize(
    "url",
    [
        "https://www.pinterest.com/board/owner/board/",
        "https://www.pinterest.com/search/pins/?q=test",
        "https://www.pinterest.com/pin/not-an-id/",
        "https://pin.it/a",
        "https://www.pinterest.com/pin/615233999110252228@evil.example/",
    ],
)
def test_non_pin_urls_are_rejected(url: str) -> None:
    with pytest.raises(UrlValidationError) as exception:
        classify_url(url)
    assert exception.value.code == "invalid_pinterest_pin_url"


def test_image_parser_selects_structured_original_not_social_size() -> None:
    metadata, assets, media_type = parse_pinterest_pin(payload(), "615233999110252228")
    assert media_type == "image"
    assert metadata["media_count"] == 1
    assert assets[0]["url"] == IMAGE_ORIGINAL
    assert (assets[0]["width"], assets[0]["height"]) == (2400, 1600)
    assert assets[0]["thumbnail_url"] == IMAGE_LOW


def test_portrait_original_remains_download_source_while_thumbnail_is_bounded() -> None:
    source = payload()
    source["images"] = {
        "orig": {"url": IMAGE_ORIGINAL, "width": 1689, "height": 2535},
        "736x": {"url": IMAGE_LOW, "width": 736, "height": 1104},
    }
    _, assets, _ = parse_pinterest_pin(source, "615233999110252228")
    assert assets[0]["url"] == IMAGE_ORIGINAL
    assert (assets[0]["width"], assets[0]["height"]) == (1689, 2535)
    assert assets[0]["thumbnail_url"] == IMAGE_LOW


def test_video_parser_selects_highest_direct_mp4_and_keeps_poster_out_of_assets() -> None:
    source = payload(video=True)
    source["images"] = {
        "orig": {"url": POSTER, "width": 1689, "height": 2535},
        "736x": {"url": IMAGE_LOW, "width": 736, "height": 1104},
    }
    _, assets, media_type = parse_pinterest_pin(source, "615233999110252228")
    assert media_type == "video"
    assert len(assets) == 1
    assert assets[0]["url"] == VIDEO_1080
    assert assets[0]["thumbnail_url"] == IMAGE_LOW
    assert [variant["url"] for variant in assets[0]["variants"]] == [VIDEO_1080, VIDEO_720]


@pytest.mark.parametrize(
    "source",
    [
        {"id": "wrong", "type": "pin", "images": {}},
        {
            "id": "615233999110252228",
            "type": "pin",
            "images": {"orig": {"url": "https://evil.example/a.jpg", "width": 100, "height": 100}},
        },
        {"id": "615233999110252228", "type": "story_pin", "images": {}},
    ],
)
def test_parser_rejects_identity_mismatch_unsafe_or_missing_media(source: dict) -> None:
    with pytest.raises(ProviderError):
        parse_pinterest_pin(source, "615233999110252228")


@dataclass
class FakeClient:
    pin: dict
    resolved: str = "https://www.pinterest.com/pin/615233999110252228/"

    async def fetch_pin(self, _: str) -> dict:
        return self.pin

    async def resolve_short_link(self, _: str) -> str:
        return self.resolved


def test_adapter_returns_available_ready_contract_for_shortlink() -> None:
    result = asyncio.run(
        PinterestProviderAdapter(client=FakeClient(payload(video=True))).extract(
            classify_url("https://pin.it/5MxsDY0"), "pinterest-test"
        )
    )
    assert result.provider is Provider.PINTEREST
    assert result.status == "ready"
    assert result.normalized_url == "https://www.pinterest.com/pin/615233999110252228/"
    assert result.media_type == "video"
    assert result.capabilities == ["metadata", "media_assets", "video_variants"]
    assert result.maturity == "available"
