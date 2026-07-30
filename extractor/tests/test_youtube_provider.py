import asyncio
import subprocess
from dataclasses import replace
from typing import Any

import pytest

from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.youtube.adapter import YouTubeProviderAdapter
from save_it_extractor.providers.youtube.client import YouTubeMetadataClient
from save_it_extractor.providers.youtube.parser import parse_youtube_metadata

VIDEO_ID = "dQw4w9WgXcQ"


def youtube_payload(**overrides: Any) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "id": VIDEO_ID,
        "title": "Public YouTube video",
        "channel": "Example Channel",
        "channel_id": "UCexample",
        "duration": 212,
        "live_status": "not_live",
        "age_limit": 0,
        "availability": "public",
        "thumbnail": f"https://i.ytimg.com/vi/{VIDEO_ID}/hqdefault.jpg",
        "thumbnails": [
            {
                "url": f"https://i.ytimg.com/vi/{VIDEO_ID}/maxresdefault.jpg",
                "width": 1280,
                "height": 720,
                "preference": 10,
            },
            {
                "url": f"https://i.ytimg.com/vi/{VIDEO_ID}/hqdefault.jpg",
                "width": 480,
                "height": 360,
                "preference": 5,
            },
        ],
        "formats": [
            {
                "format_id": "399",
                "ext": "mp4",
                "vcodec": "av01.0.08M.08",
                "acodec": "none",
                "width": 1920,
                "height": 1080,
                "fps": 30,
                "tbr": 1800,
                "filesize_approx": 48_000_000,
            },
            {
                "format_id": "337",
                "ext": "mp4",
                "vcodec": "hev1.1.6.L120",
                "acodec": "none",
                "width": 1920,
                "height": 1080,
                "fps": 30,
                "tbr": 2200,
            },
            {
                "format_id": "137",
                "ext": "mp4",
                "vcodec": "avc1.640028",
                "acodec": "none",
                "width": 1920,
                "height": 1080,
                "fps": 30,
                "tbr": 2500,
                "filesize": 55_000_000,
            },
            {
                "format_id": "248",
                "ext": "webm",
                "vcodec": "vp9",
                "acodec": "none",
                "width": 1920,
                "height": 1080,
                "fps": 30,
                "tbr": 2100,
            },
            {
                "format_id": "22",
                "ext": "mp4",
                "vcodec": "avc1.64001F",
                "acodec": "mp4a.40.2",
                "width": 1280,
                "height": 720,
                "fps": 30,
                "tbr": 1500,
            },
            {
                "format_id": "140",
                "ext": "m4a",
                "vcodec": "none",
                "acodec": "mp4a.40.2",
                "abr": 129,
                "asr": 44100,
                "filesize": 3_400_000,
            },
            {
                "format_id": "251",
                "ext": "webm",
                "vcodec": "none",
                "acodec": "opus",
                "abr": 160,
                "asr": 48000,
            },
        ],
    }
    payload.update(overrides)
    return payload


@pytest.mark.parametrize(
    ("url", "normalized", "variant"),
    [
        (
            f"https://www.youtube.com/watch?v={VIDEO_ID}",
            f"https://www.youtube.com/watch?v={VIDEO_ID}",
            "video",
        ),
        (
            f"https://m.youtube.com/watch?v={VIDEO_ID}&list=PLignored&index=2",
            f"https://www.youtube.com/watch?v={VIDEO_ID}",
            "video",
        ),
        (
            f"https://youtu.be/{VIDEO_ID}?si=tracking",
            f"https://www.youtube.com/watch?v={VIDEO_ID}",
            "video",
        ),
        (
            f"https://www.youtube.com/shorts/{VIDEO_ID}?feature=share",
            f"https://www.youtube.com/shorts/{VIDEO_ID}",
            "shorts",
        ),
    ],
)
def test_youtube_urls_are_canonicalized(url: str, normalized: str, variant: str) -> None:
    context = classify_url(url)

    assert context.normalized_url == normalized
    assert context.variant == variant


@pytest.mark.parametrize(
    ("url", "code"),
    [
        ("https://www.youtube.com/playlist?list=PLexample", "playlist_not_supported"),
        ("https://www.youtube.com/watch?list=PLexample", "playlist_not_supported"),
        (f"https://www.youtube.com/live/{VIDEO_ID}", "live_not_supported"),
        ("https://www.youtube.com/watch?v=invalid", "invalid_youtube_video_url"),
        ("https://youtu.be/not-valid", "invalid_youtube_video_url"),
        ("https://www.youtube.com/@channel", "invalid_youtube_video_url"),
    ],
)
def test_unsupported_youtube_urls_are_rejected(url: str, code: str) -> None:
    with pytest.raises(UrlValidationError) as exception:
        classify_url(url)

    assert exception.value.code == code


def test_metadata_normalization_prioritizes_mp4_h264_and_m4a() -> None:
    context = classify_url(f"https://www.youtube.com/watch?v={VIDEO_ID}")
    metadata = parse_youtube_metadata(youtube_payload(), context)

    assert metadata["video_id"] == VIDEO_ID
    assert metadata["title"] == "Public YouTube video"
    assert metadata["author_name"] == "Example Channel"
    assert metadata["duration_ms"] == 212_000
    assert [item["format_id"] for item in metadata["video_formats"]] == [
        "137",
        "22",
        "337",
        "399",
        "248",
    ]
    assert metadata["video_formats"][0]["video_codec_family"] == "h264"
    assert metadata["video_formats"][0]["requires_merge"] is True
    assert metadata["video_formats"][1]["has_audio"] is True
    assert metadata["audio_formats"][0]["format_id"] == "140"
    assert metadata["audio_formats"][0]["container"] == "m4a"
    assert metadata["conversion_plans"] == [
        {
            "id": "mp3",
            "label": "MP3",
            "source": "audio_format",
            "requires_ffmpeg": True,
            "available": False,
        }
    ]
    assert all("url" not in item for item in metadata["video_formats"])
    assert all("url" not in item for item in metadata["audio_formats"])


@pytest.mark.parametrize(
    ("overrides", "code"),
    [
        ({"is_live": True}, "live_not_supported"),
        ({"live_status": "is_upcoming"}, "live_not_supported"),
        ({"was_live": True}, "live_not_supported"),
        ({"availability": "private"}, "authentication_required"),
        ({"age_limit": 18}, "age_restricted"),
        ({"formats": [{"format_id": "1", "has_drm": True}]}, "drm_protected"),
        ({"_type": "playlist", "entries": []}, "playlist_not_supported"),
    ],
)
def test_restricted_youtube_content_is_rejected(overrides: dict[str, Any], code: str) -> None:
    context = classify_url(f"https://www.youtube.com/watch?v={VIDEO_ID}")

    with pytest.raises(ProviderError) as exception:
        parse_youtube_metadata(youtube_payload(**overrides), context)

    assert exception.value.code == code


class FakeYoutubeDL:
    options: dict[str, Any] = {}

    def __init__(self, options: dict[str, Any]) -> None:
        FakeYoutubeDL.options = options

    def __enter__(self) -> "FakeYoutubeDL":
        return self

    def __exit__(self, *_args: Any) -> None:
        pass

    def extract_info(self, url: str, download: bool) -> dict[str, Any]:
        assert url == f"https://www.youtube.com/watch?v={VIDEO_ID}"
        assert download is False
        return youtube_payload()


def test_client_uses_library_api_without_download_cookie_proxy_or_shell(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    def forbidden(*_args: Any, **_kwargs: Any) -> None:
        raise AssertionError("Subprocess execution is forbidden")

    monkeypatch.setattr(subprocess, "run", forbidden)
    monkeypatch.setattr(subprocess, "Popen", forbidden)
    client = YouTubeMetadataClient(extractor_factory=FakeYoutubeDL)
    result = asyncio.run(client.fetch(f"https://www.youtube.com/watch?v={VIDEO_ID}"))

    assert result["id"] == VIDEO_ID
    assert FakeYoutubeDL.options["skip_download"] is True
    assert FakeYoutubeDL.options["simulate"] is True
    assert FakeYoutubeDL.options["noplaylist"] is True
    assert FakeYoutubeDL.options["cookiefile"] is None
    assert FakeYoutubeDL.options["proxy"] == ""
    assert FakeYoutubeDL.options["js_runtimes"] == {}
    assert FakeYoutubeDL.options["remote_components"] == set()


def test_client_rejects_noncanonical_input_before_constructing_extractor() -> None:
    constructed = False

    def factory(_options: dict[str, Any]) -> FakeYoutubeDL:
        nonlocal constructed
        constructed = True
        return FakeYoutubeDL(_options)

    client = YouTubeMetadataClient(extractor_factory=factory)
    with pytest.raises(ProviderError) as exception:
        asyncio.run(client.fetch("https://example.com/video"))

    assert exception.value.code == "invalid_youtube_video_url"
    assert constructed is False


def test_format_resolution_returns_only_a_validated_googlevideo_source() -> None:
    class ResolverYoutubeDL(FakeYoutubeDL):
        def extract_info(self, url: str, download: bool) -> dict[str, Any]:
            payload = youtube_payload()
            payload["formats"][0]["url"] = (
                "https://rr1---sn-example.googlevideo.com/videoplayback?expire=1"
            )
            return payload

    client = YouTubeMetadataClient(extractor_factory=ResolverYoutubeDL)
    source = asyncio.run(
        client.resolve_format(
            f"https://www.youtube.com/watch?v={VIDEO_ID}",
            "399",
        )
    )

    assert source["url"].startswith("https://rr1---sn-example.googlevideo.com/")
    assert source["mime_type"] == "video/mp4"
    assert "cookie" not in source


def test_format_resolution_rejects_arbitrary_hosts() -> None:
    class UnsafeYoutubeDL(FakeYoutubeDL):
        def extract_info(self, url: str, download: bool) -> dict[str, Any]:
            payload = youtube_payload()
            payload["formats"][0]["url"] = "https://example.com/video.mp4"
            return payload

    with pytest.raises(ProviderError) as exception:
        asyncio.run(
            YouTubeMetadataClient(extractor_factory=UnsafeYoutubeDL).resolve_format(
                f"https://www.youtube.com/watch?v={VIDEO_ID}",
                "399",
            )
        )

    assert exception.value.code == "provider_response_changed"


def test_adapter_returns_ready_short_contract() -> None:
    class FakeClient:
        async def fetch(self, _url: str) -> dict[str, Any]:
            return youtube_payload()

    context = classify_url(f"https://www.youtube.com/shorts/{VIDEO_ID}")
    adapter = replace(YouTubeProviderAdapter(), client=FakeClient())
    result = asyncio.run(adapter.extract(context, "youtube-test"))

    assert result.status == "ready"
    assert result.variant == "shorts"
    assert result.media_type == "short_video"
    assert result.assets == []
    assert "video_formats" in result.capabilities
