import asyncio
import json
from dataclasses import dataclass

import pytest

from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.facebook.adapter import FacebookProviderAdapter
from save_it_extractor.providers.facebook.parser import parse_facebook_video

VIDEO_ID = "588631943886661"
HD = "https://video-ams2-1.xx.fbcdn.net/v/t42/example-hd.mp4?oe=redacted"
SD = "https://video-ams2-1.xx.fbcdn.net/v/t42/example-sd.mp4?oe=redacted"
POSTER = "https://scontent-ams2-1.xx.fbcdn.net/v/t39/example.jpg?oh=redacted"
DASH = """<MPD xmlns=\"urn:mpeg:dash:schema:mpd:2011\"><Period>
<AdaptationSet mimeType=\"video/mp4\">
<Representation codecs=\"avc1.64001f\" width=\"720\" height=\"1280\" bandwidth=\"3000000\">
<BaseURL>https://video.fist8-1.fna.fbcdn.net/v/t42/example-hd.mp4?oe=redacted</BaseURL>
</Representation></AdaptationSet>
<AdaptationSet mimeType=\"audio/mp4\"><Representation codecs=\"mp4a.40.5\" bandwidth=\"64000\">
<BaseURL>https://video.fist8-1.fna.fbcdn.net/v/t42/example-audio.mp4?oe=redacted</BaseURL>
</Representation></AdaptationSet>
</Period></MPD>"""


def page(
    video_id: str = VIDEO_ID,
    *,
    reel: bool = False,
    include_media: bool = True,
    duplicate: bool = False,
) -> str:
    media = {
        "id": video_id,
        "videoId": video_id,
        "width": 1920,
        "height": 1080,
        "playable_duration_in_ms": 1234,
        "owner": {"name": "Public creator"},
        "preferred_thumbnail": {"image": {"uri": POSTER}},
        "videoDeliveryLegacyFields": {
            "browser_native_hd_url": HD,
            "browser_native_sd_url": SD,
            # Equivalent signed identities must not create duplicate output rows.
            "playable_url_quality_hd": HD + "&signature=other",
            "dash_manifest_xml_string": DASH,
        }
        if include_media
        else {},
    }
    data = (
        {"video": {"creation_story": {"short_form_video_context": {"playback_video": media}}}}
        if reel
        else {"video": {"story": {"attachments": [{"media": media}]}}}
    )
    state = {"require": [None, [None, None, None, "__bbox", {"result": {"data": data}}]]}
    scripts = [json.dumps(state)]
    if duplicate:
        scripts.append(json.dumps(state))
    return "".join(f"<script data-sjs>{script}</script>" for script in scripts)


def test_facebook_video_and_reel_urls_normalize_without_tracking() -> None:
    video = classify_url(
        "https://www.facebook.com/100071784061914/videos/588631943886661/?ref=sharing"
    )
    watch = classify_url("https://www.facebook.com/watch/?v=588631943886661&ref=sharing")
    reel = classify_url("https://facebook.com/reel/588631943886661/?mibextid=abc")
    assert (video.provider, video.variant) == (Provider.FACEBOOK, "video")
    assert (
        video.normalized_url == "https://www.facebook.com/100071784061914/videos/588631943886661/"
    )
    assert watch.normalized_url == "https://www.facebook.com/watch/?v=588631943886661"
    assert (reel.provider, reel.variant) == (Provider.FACEBOOK, "reel")
    assert reel.normalized_url == "https://www.facebook.com/reel/588631943886661"


@pytest.mark.parametrize(
    "url",
    [
        "https://www.facebook.com/owner",
        "https://www.facebook.com/groups/example",
        "https://www.facebook.com/marketplace/item/123",
        "https://www.facebook.com/photo.php?fbid=588631943886661",
        "https://www.facebook.com/reel/not-an-id",
        "https://fb.watch/x",
    ],
)
def test_non_media_and_unproven_short_urls_are_rejected(url: str) -> None:
    with pytest.raises(UrlValidationError) as exception:
        classify_url(url)
    assert exception.value.code == "invalid_facebook_media_url"


def test_relay_parser_extracts_identity_bound_cobalt_native_fields() -> None:
    metadata, assets, media_type = parse_facebook_video(page(duplicate=True), VIDEO_ID)
    assert media_type == "video"
    assert metadata["post_id"] == VIDEO_ID
    assert metadata["thumbnail_url"] == POSTER
    assert assets[0]["url"] == HD
    assert assets[0]["variants"][0]["quality_label"] == "HD · 720×1280 · 3.0 Mbps"
    assert all("1920×1080" not in item["quality_label"] for item in assets[0]["variants"])


def test_dash_parser_accepts_audible_h264_and_fna_cdn_without_parent_dimension_leakage() -> None:
    _, assets, _ = parse_facebook_video(page(), VIDEO_ID)
    variant = assets[0]["variants"][0]
    assert variant["width"] == 720
    assert variant["height"] == 1280
    assert variant["bitrate"] == 3_000_000


def test_facebook_story_share_and_alias_urls_are_accepted_without_tracking() -> None:
    cases = {
        "https://m.facebook.com/reels/588631943886661/?_rdr": "https://www.facebook.com/reel/588631943886661",
        "https://web.facebook.com/video.php?v=588631943886661&ref=x": "https://www.facebook.com/video.php?v=588631943886661",
        "https://www.facebook.com/story.php?story_fbid=100000000000001&id=100000000000002&hpir=1": "https://www.facebook.com/story.php?story_fbid=100000000000001&id=100000000000002",
        "https://www.facebook.com/share/r/SyntheticShare/?mibextid=x": "https://www.facebook.com/share/r/SyntheticShare",
        "https://fb.watch/SyntheticLink/?fs=e": "https://fb.watch/SyntheticLink",
    }
    for raw, expected in cases.items():
        assert classify_url(raw).normalized_url == expected


def test_reel_parser_uses_known_playback_video_path() -> None:
    metadata, assets, media_type = parse_facebook_video(page(reel=True), VIDEO_ID)
    assert media_type == "video"
    assert metadata["author_name"] == "Public creator"
    assert assets[0]["thumbnail_url"] == POSTER


@pytest.mark.parametrize(
    "html",
    [
        "<html></html>",
        page(video_id="999999999999999"),
        page(include_media=False),
        page().replace("video-ams2-1.xx.fbcdn.net", "evil.example"),
    ],
)
def test_parser_rejects_malformed_identity_mismatch_missing_or_unsafe_media(html: str) -> None:
    with pytest.raises(ProviderError):
        parse_facebook_video(html, VIDEO_ID)


@dataclass
class FakeClient:
    async def fetch_page(self, _: str) -> str:
        return page(reel=True)


def test_adapter_returns_available_ready_contract_for_reel() -> None:
    result = asyncio.run(
        FacebookProviderAdapter(client=FakeClient()).extract(
            classify_url(f"https://www.facebook.com/reel/{VIDEO_ID}"), "facebook-test"
        )
    )
    assert result.provider is Provider.FACEBOOK
    assert result.variant == "reel"
    assert result.status == "ready"
    assert result.maturity == "available"
    assert result.capabilities == ["metadata", "media_assets", "video_variants"]
