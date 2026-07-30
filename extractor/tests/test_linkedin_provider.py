import asyncio
import html
import socket
from dataclasses import replace

import httpx
import pytest

from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.linkedin import network
from save_it_extractor.providers.linkedin.adapter import LinkedInProviderAdapter
from save_it_extractor.providers.linkedin.network import (
    LinkedInMetadataClient,
    is_allowed_asset_url,
)
from save_it_extractor.providers.linkedin.parser import parse_linkedin_document

ACTIVITY_ID = "7181234567890123456"
ACTIVITY_URL = f"https://www.linkedin.com/feed/update/urn:li:activity:{ACTIVITY_ID}/"
IMAGE_ONE = "https://media.licdn.com/dms/image/one.jpg"
IMAGE_TWO = "https://media.licdn.com/dms/image/two.jpg"
VIDEO = "https://dms.licdn.com/playlist/video.mp4"
VIDEO_720 = "https://dms.licdn.com/video/mp4-720p-30fp-crf28/source"
VIDEO_640 = "https://dms.licdn.com/video/mp4-640p-30fp-crf28/source"


def document(
    *,
    images: list[str] | None = None,
    video: str | None = None,
    include_text: bool = True,
) -> str:
    tags = (
        [
            '<meta property="og:title" content="Public LinkedIn post">',
            '<meta property="og:description" content="Public &amp; structured metadata">',
            '<meta property="article:author" content="Example Organization">',
            '<meta property="article:published_time" content="2026-07-28T12:00:00Z">',
        ]
        if include_text
        else []
    )
    for image in images or []:
        tags.append(f'<meta property="og:image" content="{image}">')
    if images:
        tags.extend(
            [
                '<meta property="og:image:type" content="image/jpeg">',
                '<meta property="og:image:width" content="1200">',
                '<meta property="og:image:height" content="627">',
            ]
        )
    if video:
        tags.extend(
            [
                f'<meta property="og:video:secure_url" content="{video}">',
                '<meta property="og:video:type" content="video/mp4">',
                '<meta property="og:video:width" content="1280">',
                '<meta property="og:video:height" content="720">',
                '<meta property="og:video:duration" content="12.5">',
            ]
        )
    return f"<html><head>{''.join(tags)}</head><body>Public post</body></html>"


@pytest.mark.parametrize(
    ("url", "normalized", "variant"),
    [
        (
            f"https://www.linkedin.com/feed/update/urn:li:activity:{ACTIVITY_ID}"
            "?trackingId=secret&utm_source=share#fragment",
            ACTIVITY_URL,
            "activity",
        ),
        (
            f"https://m.linkedin.com/posts/example_activity-{ACTIVITY_ID}-sample"
            "?trk=public_post&lipi=ignored",
            f"https://www.linkedin.com/posts/example_activity-{ACTIVITY_ID}-sample/",
            "post",
        ),
        (
            f"https://linkedin.com/posts/example_activity-{ACTIVITY_ID}-sample?midToken=ignored",
            f"https://www.linkedin.com/posts/example_activity-{ACTIVITY_ID}-sample/",
            "post",
        ),
    ],
)
def test_linkedin_urls_are_canonicalized(url: str, normalized: str, variant: str) -> None:
    context = classify_url(url)
    assert context.provider is Provider.LINKEDIN
    assert context.normalized_url == normalized
    assert context.variant == variant
    assert "tracking" not in context.normalized_url
    assert "utm_" not in context.normalized_url


@pytest.mark.parametrize(
    "url",
    [
        "https://linkedin.com/in/example",
        "https://linkedin.com/company/example",
        "https://linkedin.com/jobs/view/123",
        "https://linkedin.com/learning/example",
        "https://linkedin.com/events/123",
        "https://linkedin.com/newsletters/example",
        "https://linkedin.com/pulse/example",
        "https://linkedin.com/login",
        "https://linkedin.com/messaging/thread/123",
        "https://linkedin.com/search/results/content/",
        "https://linkedin.com/feed/",
        "https://linkedin.com/shareArticle?url=https://example.com",
        "https://linkedin.com/posts/encoded%2Fpath",
        "https://user:password@linkedin.com/posts/example-public-post",
        "https://linkedin.com:8443/posts/example-public-post",
        "ftp://linkedin.com/posts/example-public-post",
        "https://linkedin.com.evil.example/posts/example",
    ],
)
def test_non_post_linkedin_urls_are_rejected(url: str) -> None:
    with pytest.raises(UrlValidationError):
        classify_url(url)


def test_linkedin_short_links_are_rejected_with_explicit_error() -> None:
    with pytest.raises(UrlValidationError) as exception:
        classify_url("https://lnkd.in/example")

    assert exception.value.code == "linkedin_short_url_not_supported"


@pytest.mark.parametrize(
    ("page", "expected_type", "count"),
    [
        (document(images=[IMAGE_ONE]), "image", 1),
        (document(images=[IMAGE_ONE], video=VIDEO), "video", 1),
        (document(images=[IMAGE_ONE, IMAGE_TWO]), "carousel", 2),
        (document(images=[]), "text", 0),
    ],
)
def test_parser_supports_public_linkedin_media_shapes(
    page: str, expected_type: str, count: int
) -> None:
    metadata, assets, media_type = parse_linkedin_document(page, ACTIVITY_URL)

    assert media_type == expected_type
    assert metadata["post_id"] == ACTIVITY_ID
    assert metadata["title"] == "Public LinkedIn post"
    assert metadata["description"] == "Public & structured metadata"
    assert metadata["author_name"] == "Example Organization"
    assert metadata["media_count"] == count
    assert [asset["order"] for asset in assets] == list(range(1, count + 1))
    assert not any("download" in asset or "binary" in asset for asset in assets)


def test_json_ld_is_a_safe_metadata_fallback() -> None:
    page = f"""
    <html><head><script type="application/ld+json">{{
      "@context": "https://schema.org",
      "@type": "SocialMediaPosting",
      "headline": "Structured public post",
      "description": "Safe excerpt",
      "datePublished": "2026-07-28T13:30:00Z",
      "author": {{"@type": "Organization", "name": "Example Org"}},
      "image": ["{IMAGE_ONE}", "{IMAGE_TWO}"]
    }}</script></head></html>
    """
    metadata, assets, media_type = parse_linkedin_document(page, ACTIVITY_URL)

    assert metadata["title"] == "Structured public post"
    assert metadata["author_name"] == "Example Org"
    assert metadata["published_at"] == "2026-07-28T13:30:00Z"
    assert media_type == "carousel"
    assert len(assets) == 2


def test_public_video_wins_over_login_markers_and_maps_structured_metadata() -> None:
    page = f"""
    <html><head>
      <script type="application/ld+json">{{
        "@context": "https://schema.org",
        "@type": "VideoObject",
        "name": "Public native video",
        "description": "A public LinkedIn video",
        "contentUrl": "{VIDEO_640}",
        "thumbnailUrl": "{IMAGE_ONE}",
        "duration": "PT1M51S",
        "width": 720,
        "height": 1280,
        "creator": {{"@type": "Organization", "name": "Example Organization"}},
        "uploadDate": "2026-07-28T12:00:00Z"
      }}</script>
    </head><body>
      <form id="join-form" action="/uas/login">Sign in</form>
      <video
        data-sources='[
          {{"src":"{VIDEO_640}","type":"video/mp4","data-bitrate":750872}},
          {{"src":"{VIDEO_720}","type":"video/mp4","data-bitrate":979082}}
        ]'
        data-poster-url="{IMAGE_ONE}"
        data-captions-url="https://dms.licdn.com/captions/en.vtt"
        data-digitalmedia-asset-urn="urn:li:digitalmediaAsset:D4D05AQFOGXP0m8vpfQ"
      ></video>
    </body></html>
    """

    metadata, assets, media_type = parse_linkedin_document(page, ACTIVITY_URL)

    assert media_type == "video"
    assert metadata["title"] == "Public native video"
    assert metadata["author_name"] == "Example Organization"
    assert metadata["duration_ms"] == 111_000
    assert metadata["width"] == 720
    assert metadata["height"] == 1280
    assert metadata["orientation"] == "portrait"
    assert metadata["thumbnail_url"] == IMAGE_ONE
    assert metadata["captions_url"] == "https://dms.licdn.com/captions/en.vtt"
    assert metadata["media_asset_id"] == "urn:li:digitalmediaAsset:D4D05AQFOGXP0m8vpfQ"
    assert [item["quality_label"] for item in assets[0]["variants"]] == [
        "720p MP4",
        "640p MP4",
    ]
    assert [item["bitrate"] for item in assets[0]["variants"]] == [979082, 750872]
    assert assets[0]["variants"][0]["is_preferred"] is True


def test_graph_video_object_and_entity_encoded_sources_are_supported() -> None:
    sources = html.escape(
        f'[{{"src":"{VIDEO_720}","type":"video/mp4","data-bitrate":900000}}]',
        quote=True,
    )
    page = f"""
    <html><head><script type="application/ld+json">{{
      "@context": "https://schema.org",
      "@graph": [
        {{"@type": "SocialMediaPosting", "headline": "Graph post"}},
        {{"@type": "VideoObject", "contentUrl": "{VIDEO_720}",
          "thumbnailUrl": "{IMAGE_ONE}", "duration": "PT8S"}}
      ]
    }}</script></head><body><video data-sources="{sources}"></video></body></html>
    """

    metadata, assets, media_type = parse_linkedin_document(page, ACTIVITY_URL)

    assert media_type == "video"
    assert metadata["duration_ms"] == 8_000
    assert assets[0]["url"] == VIDEO_720
    assert len(assets[0]["variants"]) == 1


def test_malformed_sources_are_ignored_and_duplicate_variants_are_deduplicated() -> None:
    page = f"""
    <html><head>
      <meta property="og:title" content="Public post">
      <meta property="og:video" content="{VIDEO_720}">
    </head><body>
      <video data-sources="not-json"></video>
      <video data-sources='[
        {{"src":"{VIDEO_720}","type":"video/mp4","data-bitrate":1}},
        {{"src":"https://dms.licdn.com.evil.example/video.mp4","type":"video/mp4"}}
      ]'></video>
    </body></html>
    """

    _, assets, media_type = parse_linkedin_document(page, ACTIVITY_URL)

    assert media_type == "video"
    assert len(assets[0]["variants"]) == 1
    assert assets[0]["variants"][0]["url"] == VIDEO_720


@pytest.mark.parametrize(
    ("page", "code"),
    [
        ('<html><form id="join-form">Sign in</form></html>', "authentication_required"),
        ("<html>This post is no longer available</html>", "unavailable_content"),
        ("<html><head><title>LinkedIn</title></head></html>", "parsing_failed"),
    ],
)
def test_authwall_unavailable_and_malformed_pages_are_controlled(page: str, code: str) -> None:
    with pytest.raises(ProviderError) as exception:
        parse_linkedin_document(page, ACTIVITY_URL)

    assert exception.value.code == code
    assert "traceback" not in exception.value.message.lower()


def test_asset_host_matching_is_exact_suffix_and_https_only() -> None:
    assert is_allowed_asset_url(IMAGE_ONE)
    assert is_allowed_asset_url(VIDEO)
    assert not is_allowed_asset_url("https://licdn.com.evil.example/image.jpg")
    assert not is_allowed_asset_url("https://licdn.com/image.jpg")
    assert not is_allowed_asset_url("http://media.licdn.com/image.jpg")
    assert not is_allowed_asset_url("https://media.licdn.com:8443/image.jpg")
    assert not is_allowed_asset_url("https://example.com/image.jpg")


def public_dns(*_args, **_kwargs):
    return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("108.174.10.10", 443))]


def test_network_client_uses_canonical_url_without_credentials_or_proxy(
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
            text=document(images=[IMAGE_ONE]),
        )

    page = asyncio.run(LinkedInMetadataClient(httpx.MockTransport(handler)).fetch(ACTIVITY_URL))
    assert "Public LinkedIn post" in page
    assert seen is not None
    assert seen.url == httpx.URL(ACTIVITY_URL)
    assert "cookie" not in seen.headers
    assert "authorization" not in seen.headers


def test_redirect_hosts_authwall_and_private_dns_are_revalidated(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)

    async def arbitrary(_: httpx.Request) -> httpx.Response:
        return httpx.Response(302, headers={"location": "https://example.com/private"})

    with pytest.raises(ProviderError) as unsafe:
        asyncio.run(LinkedInMetadataClient(httpx.MockTransport(arbitrary)).fetch(ACTIVITY_URL))
    assert unsafe.value.code == "disallowed_redirect"

    async def authwall(_: httpx.Request) -> httpx.Response:
        return httpx.Response(
            302,
            headers={"location": "https://www.linkedin.com/authwall?trk=guest"},
        )

    with pytest.raises(ProviderError) as authentication:
        asyncio.run(LinkedInMetadataClient(httpx.MockTransport(authwall)).fetch(ACTIVITY_URL))
    assert authentication.value.code == "authentication_required"

    monkeypatch.setattr(
        socket,
        "getaddrinfo",
        lambda *_args, **_kwargs: [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("127.0.0.1", 443))],
    )
    with pytest.raises(ProviderError) as private:
        asyncio.run(LinkedInMetadataClient(httpx.MockTransport(arbitrary)).fetch(ACTIVITY_URL))
    assert private.value.code == "disallowed_redirect"


@pytest.mark.parametrize(
    ("status", "code"),
    [
        (401, "authentication_required"),
        (403, "upstream_blocked"),
        (404, "unavailable_content"),
        (429, "rate_limited"),
        (500, "temporary_provider_error"),
        (999, "upstream_blocked"),
    ],
)
def test_upstream_statuses_are_mapped_safely(
    monkeypatch: pytest.MonkeyPatch, status: int, code: str
) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)

    async def handler(_: httpx.Request) -> httpx.Response:
        return httpx.Response(status, headers={"content-type": "text/html"})

    with pytest.raises(ProviderError) as exception:
        asyncio.run(LinkedInMetadataClient(httpx.MockTransport(handler)).fetch(ACTIVITY_URL))
    assert exception.value.code == code


def test_timeout_and_response_size_are_bounded(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)
    monkeypatch.setattr(
        network,
        "settings",
        replace(network.settings, linkedin_max_metadata_bytes=32),
    )

    async def oversized(_: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            headers={"content-type": "text/html"},
            content=b"a" * 64,
        )

    with pytest.raises(ProviderError) as too_large:
        asyncio.run(LinkedInMetadataClient(httpx.MockTransport(oversized)).fetch(ACTIVITY_URL))
    assert too_large.value.code == "provider_response_too_large"

    async def timeout(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("sensitive timeout detail", request=request)

    with pytest.raises(ProviderError) as timed_out:
        asyncio.run(LinkedInMetadataClient(httpx.MockTransport(timeout)).fetch(ACTIVITY_URL))
    assert timed_out.value.code == "provider_timeout"
    assert "sensitive" not in timed_out.value.message


def test_non_html_metadata_response_is_rejected(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(socket, "getaddrinfo", public_dns)

    async def json_response(_: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            headers={"content-type": "application/json"},
            json={"unexpected": "payload"},
        )

    with pytest.raises(ProviderError) as exception:
        asyncio.run(LinkedInMetadataClient(httpx.MockTransport(json_response)).fetch(ACTIVITY_URL))
    assert exception.value.code == "parsing_failed"


def test_adapter_returns_stable_ready_contract_without_binary_fetch() -> None:
    class FakeClient:
        async def fetch(self, normalized_url: str) -> str:
            assert normalized_url == ACTIVITY_URL
            return document(images=[IMAGE_ONE, IMAGE_TWO])

    result = asyncio.run(
        LinkedInProviderAdapter(client=FakeClient()).extract(
            classify_url(ACTIVITY_URL),
            "request-linkedin",
        )
    )
    assert result.status == "ready"
    assert result.maturity == "stable"
    assert result.media_type == "carousel"
    assert result.capabilities == ["metadata", "media_assets", "multiple_assets"]
    assert len(result.warnings) == 2


@pytest.mark.live
def test_optional_live_public_linkedin_post() -> None:
    import os

    url = os.getenv("SAVE_IT_LINKEDIN_LIVE_URL")
    if os.getenv("SAVE_IT_RUN_LINKEDIN_LIVE_TESTS") != "1" or not url:
        pytest.skip(
            "Set SAVE_IT_RUN_LINKEDIN_LIVE_TESTS=1 and SAVE_IT_LINKEDIN_LIVE_URL "
            "to run provider checks."
        )

    result = asyncio.run(LinkedInProviderAdapter().extract(classify_url(url), "live-linkedin"))
    assert result.status == "ready"
    assert result.maturity == "stable"
