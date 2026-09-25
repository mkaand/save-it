import asyncio
import html
import json
import socket
from struct import pack

import httpx
import pytest

from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.egress import is_allowed_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.facebook.adapter import FacebookProviderAdapter
from save_it_extractor.providers.facebook.embeds import oembed_video_target, post_embed_target
from save_it_extractor.providers.facebook.network import (
    ASSET_HOSTS,
    FacebookMetadataClient,
    _mp4_tracks,
)
from save_it_extractor.providers.facebook.parser import _dash_variants, parse_facebook_video
from test_facebook_provider import DASH, VIDEO_ID, page

STORY = "100000000000001"
OWNER = "100000000000002"
TARGET = f"https://www.facebook.com/reel/{VIDEO_ID}"


def embed(video_id=VIDEO_ID):
    config = {
        "video_id": video_id,
        "videoData": [
            {"video_id": video_id, "video_url": f"https://www.facebook.com/reel/{video_id}/"}
        ],
    }
    return (
        "<script>new ServerJS().handle("
        + json.dumps(
            {"instances": [["primary", ["VideoConfig", "VideoPlayerHTML5Oz"], [config], 1]]}
        )
        + ");</script>"
    )


def oembed(target=TARGET):
    return {
        "provider_name": "Facebook",
        "type": "video",
        "html": f'<div class="fb-video"><a href="{target}">Video</a></div>',
    }


@pytest.fixture
def public_dns(monkeypatch):
    monkeypatch.setattr(
        socket,
        "getaddrinfo",
        lambda *a, **k: [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("8.8.8.8", 443))],
    )


def test_official_embed_primary_config_resolves_not_unrelated_links():
    assert (
        post_embed_target(
            embed() + '<a href="https://www.facebook.com/reel/999999999999999">Related</a>'
        )
        == TARGET
    )
    assert oembed_video_target(oembed()) == TARGET


@pytest.mark.parametrize(
    "body",
    [
        embed() + embed("999999999999999"),
        embed().replace("VideoConfig", "OtherConfig"),
        embed().replace("facebook.com", "facebook.com.evil.example"),
        embed().replace('"video_id": "' + VIDEO_ID + '"', '"video_id": "999999999999999"', 1),
        "<script>runAnything();</script>",
    ],
)
def test_embed_rejects_ambiguous_wrong_identity_unsafe_and_malformed(body):
    with pytest.raises(ProviderError):
        post_embed_target(body)


def test_oembed_rejects_echoed_short_url_and_conflicting_ids():
    for payload in [
        oembed("https://fb.watch/SyntheticLink/"),
        oembed("https://www.facebook.com/share/r/SyntheticShare/"),
        {"provider_name": "Other", "type": "video", "html": ""},
    ]:
        with pytest.raises(ProviderError):
            oembed_video_target(payload)
    payload = oembed()
    payload["html"] += '<a href="https://www.facebook.com/reel/999999999999999">Other</a>'
    with pytest.raises(ProviderError):
        oembed_video_target(payload)


def test_story_login_redirect_uses_official_embed_and_retains_only_anonymous_page_cookies(
    public_dns,
):
    requests = []

    def handle(request):
        requests.append(request)
        if request.url.path.startswith("/share/"):
            assert request.url.params["mibextid"] == "synthetic"
            return httpx.Response(
                302,
                headers={
                    "location": f"/story.php?story_fbid={STORY}&id={OWNER}&hpir=1",
                    "set-cookie": "anonymous=synthetic; Domain=.facebook.com; Path=/; "
                    "Secure; HttpOnly; SameSite=Lax",
                },
            )
        if request.url.path == "/story.php":
            assert request.headers.get("cookie") == "anonymous=synthetic"
            return httpx.Response(302, headers={"location": "/login/?next=ignored"})
        if request.url.path == "/plugins/post.php":
            assert "cookie" not in request.headers
            assert (
                request.url.params["href"]
                == f"https://www.facebook.com/story.php?story_fbid={STORY}&id={OWNER}"
            )
            return httpx.Response(200, headers={"content-type": "text/html"}, text=embed())
        assert request.url.path == "/reel/" + VIDEO_ID
        assert "cookie" not in request.headers
        return httpx.Response(
            200, headers={"content-type": "text/html"}, text="<div>Login modal</div>" + page()
        )

    client = FacebookMetadataClient(httpx.MockTransport(handle))
    result = asyncio.run(
        client.fetch_page("https://www.facebook.com/share/r/SyntheticShare/?mibextid=synthetic")
    )
    assert result.url == TARGET
    assert parse_facebook_video(result.html, VIDEO_ID)[2] == "video"
    assert all(request.url.path != "/login/" for request in requests)


def test_fbwatch_uses_tokenless_oembed_and_never_imports_cookies(public_dns):
    def handle(request):
        assert "authorization" not in request.headers and "cookie" not in request.headers
        if request.url.host == "graph.facebook.com":
            assert request.url.path == "/v26.0/oembed_video"
            assert set(request.url.params) == {"url", "omitscript"}
            assert request.url.params["url"] == "https://fb.watch/SyntheticLink/?fs=e"
            return httpx.Response(
                200,
                json=oembed(),
                headers={"set-cookie": "never=forward; Domain=.facebook.com; Path=/; Secure"},
            )
        assert str(request.url) == TARGET
        return httpx.Response(200, headers={"content-type": "text/html"}, text=page())

    result = asyncio.run(
        FacebookMetadataClient(httpx.MockTransport(handle)).fetch_page(
            "https://fb.watch/SyntheticLink/?fs=e"
        )
    )
    assert result.url == TARGET


@pytest.mark.parametrize(
    "target",
    [
        "http://www.facebook.com/reel/12345678901",
        "https://attacker.example/video",
        "https://user:secret@www.facebook.com/reel/12345678901",
        "https://www.facebook.com:8443/reel/12345678901",
        "https://127.0.0.1/media",
        "https://www.facebook.com/groups/private",
    ],
)
def test_unsafe_page_redirect_is_not_followed(public_dns, target):
    calls = []

    def handle(request):
        calls.append(request)
        return httpx.Response(302, headers={"location": target})

    with pytest.raises(ProviderError):
        asyncio.run(FacebookMetadataClient(httpx.MockTransport(handle)).fetch_page(TARGET))
    assert len(calls) == 1


def test_public_surface_rejects_unsafe_dns(monkeypatch):
    monkeypatch.setattr(
        socket,
        "getaddrinfo",
        lambda *a, **k: [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("127.0.0.1", 443))],
    )
    with pytest.raises(ProviderError) as error:
        asyncio.run(
            FacebookMetadataClient(
                httpx.MockTransport(lambda r: pytest.fail("must not send"))
            ).fetch_page("https://fb.watch/SyntheticLink/")
        )
    assert error.value.code == "upstream_unavailable"


@pytest.mark.parametrize(
    "host", ["facebook.com", "www.facebook.com", "m.facebook.com", "web.facebook.com"]
)
@pytest.mark.parametrize(
    "path",
    [
        f"/reel/{VIDEO_ID}/",
        f"/reels/{VIDEO_ID}/",
        f"/watch/?v={VIDEO_ID}",
        f"/video.php?v={VIDEO_ID}",
        f"/creator/videos/caption/{VIDEO_ID}/",
        f"/story.php?story_fbid={STORY}&id={OWNER}",
        f"/permalink.php?story_fbid={STORY}&id={OWNER}",
        f"/creator/posts/caption/{STORY}/",
        "/share/r/SyntheticShare/",
        "/share/v/SyntheticShare/",
    ],
)
def test_supported_page_families(host, path):
    assert classify_url("https://" + host + path).normalized_url.startswith(
        "https://www.facebook.com/"
    )


@pytest.mark.parametrize(
    "path",
    [
        "/story.php?story_fbid=bad&id=123",
        f"/story.php?story_fbid={STORY}",
        "/share/r/",
        "/share/v/a/b",
        "/photo.php?fbid=100000000000001",
        "/groups/public",
        "/creator",
    ],
)
def test_non_video_families_rejected(path):
    with pytest.raises(UrlValidationError):
        classify_url("https://www.facebook.com" + path)


def test_dash_xml_audio_namespace_escaping_and_signed_query_preservation():
    manifest = DASH.replace("?oe=redacted", "?a=one&amp;b=two%2Fthree")
    for xml in [manifest, html.escape(manifest)]:
        variants = _dash_variants({"videoDeliveryLegacyFields": {"dash_manifest_xml_string": xml}})
        assert len(variants) == 2
        assert variants[0]["width"] == 720 and variants[0]["height"] == 1280
        assert variants[1]["kind"] == "audio" and variants[1]["codec"] == "mp4a.40.5"
        assert variants[1]["url"].endswith("?a=one&b=two%2Fthree")
    assert (
        _dash_variants(
            {
                "videoDeliveryLegacyFields": {
                    "dash_manifest_xml_string": '<!DOCTYPE MPD [<!ENTITY x "boom">]><MPD>&x;</MPD>'
                }
            }
        )
        == []
    )


def test_muted_source_never_pairs_audio_and_probes_override_parent_dimensions():
    class Client:
        def __init__(self, muted=False, audio=False):
            self.muted, self.audio = muted, audio

        async def fetch_page(self, _):
            result = page()
            return (
                result.replace(
                    '"width": 1920', '"audio_availability": "AVAILABLE_BUT_MUTED", "width": 1920'
                )
                if self.muted
                else result
            )

        async def probe_mp4(self, url):
            return {
                "width": 720 if "hd" in url else 360,
                "height": 1280 if "hd" in url else 640,
                "has_audio": self.audio,
            }

    for muted, audio in [(False, True), (False, False), (True, False)]:
        result = asyncio.run(
            FacebookProviderAdapter(client=Client(muted, audio)).extract(
                classify_url(TARGET), "synthetic"
            )
        )
        variants = result.assets[0]["variants"]
        assert len(variants) == 2
        assert [(v["width"], v["height"]) for v in variants] == [(720, 1280), (360, 640)]
        assert all(bool(v.get("audio_url")) == (not muted and not audio) for v in variants)
        assert "_facebook_audio" not in result.assets[0]


def test_story_attachment_binding_ignores_related_media():
    video = json.loads(page()[17:-9])["require"][1][4]["result"]["data"]["video"]["story"][
        "attachments"
    ][0]["media"]
    state = {
        "id": STORY,
        "attachments": [{"media": video, "recommendations": [{"id": "999999999999999", **video}]}],
        "related": [{"id": "other", **video}],
    }
    _, assets, _ = parse_facebook_video(
        "<script data-sjs>" + json.dumps(state) + "</script>", story_id=STORY
    )
    assert len(assets) == 1
    with pytest.raises(ProviderError):
        parse_facebook_video(
            "<script data-sjs>" + json.dumps(state) + "</script>", expected_video_id=STORY
        )


@pytest.mark.parametrize(
    "host,allowed",
    [
        ("video.edge.fna.fbcdn.net", True),
        ("scontent.edge.xx.fbcdn.net", True),
        ("fna.fbcdn.net", False),
        ("xx.fbcdn.net", False),
        ("fna.fbcdn.net.evil.example", False),
        ("evilfna.fbcdn.net", False),
        ("other.fbcdn.net", False),
    ],
)
def test_narrow_facebook_cdn_families(host, allowed):
    assert is_allowed_url("https://" + host + "/object", ASSET_HOSTS) is allowed


def test_bounded_mp4_probe_tracks_and_dimensions():
    def box(name, payload):
        return pack(">I", 8 + len(payload)) + name + payload

    video = box(
        b"trak",
        box(b"tkhd", b"\0" * 76 + pack(">II", 720 << 16, 1280 << 16))
        + box(b"mdia", box(b"hdlr", b"\0" * 8 + b"vide")),
    )
    audio = box(b"trak", box(b"mdia", box(b"hdlr", b"\0" * 8 + b"soun")))
    assert _mp4_tracks(box(b"moov", video + audio)) == {
        "width": 720,
        "height": 1280,
        "has_audio": True,
    }
    assert _mp4_tracks(box(b"moov", video))["has_audio"] is False
    assert _mp4_tracks(b"invalid") is None
