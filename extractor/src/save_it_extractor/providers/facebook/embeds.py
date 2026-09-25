"""Identity resolution from Facebook's official, public embed documents.

No JavaScript is executed. Only JSON literals at the observed ServerJS.handle
boundary and the registered primary VideoConfig are read. Feed/CDN URL scraping
is deliberately not a fallback.
"""

import json
import re
from html.parser import HTMLParser
from urllib.parse import parse_qs, urlsplit

from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError


class _EmbedDocument(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.links: list[str] = []
        self.scripts: list[str] = []
        self.active = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag == "a" and isinstance(href := dict(attrs).get("href"), str):
            self.links.append(href)
        if tag == "script":
            self.active = True
            self.scripts.append("")

    def handle_endtag(self, tag: str) -> None:
        if tag == "script":
            self.active = False

    def handle_data(self, data: str) -> None:
        if self.active:
            self.scripts[-1] += data


def direct_video_target(raw: str) -> tuple[str, str] | None:
    """Canonicalize a proven direct video URL, never a story/share identifier."""
    try:
        parsed = urlsplit(raw)
        if parsed.scheme != "https":
            return None
        context = classify_url(raw)
    except (UrlValidationError, ValueError):
        return None
    if context.provider is not Provider.FACEBOOK:
        return None
    parsed = urlsplit(context.normalized_url)
    if parsed.hostname != "www.facebook.com":
        return None
    if parsed.path in {"/watch", "/watch/", "/video.php"}:
        identifier = parse_qs(parsed.query).get("v", [""])[0]
    elif re.fullmatch(r"/reel/[0-9]+/?|/[^/]+/videos/(?:[^/]+/)?[0-9]+/?", parsed.path):
        identifier = parsed.path.rstrip("/").rsplit("/", 1)[-1]
    else:
        return None
    return context.normalized_url, identifier


def oembed_video_target(payload: dict) -> str:
    if payload.get("provider_name") != "Facebook" or payload.get("type") != "video":
        raise _unavailable()
    html = payload.get("html")
    if not isinstance(html, str) or len(html) > 256_000:
        raise _unavailable()
    document = _EmbedDocument()
    document.feed(html)
    targets = [target for link in document.links if (target := direct_video_target(link))]
    if not targets or len({identifier for _, identifier in targets}) != 1:
        raise _unavailable()
    return sorted(targets)[0][0]


def post_embed_target(html: str) -> str:
    document = _EmbedDocument()
    document.feed(html)
    targets: list[tuple[str, str]] = []
    decoder = json.JSONDecoder()
    for script in document.scripts:
        for boundary in re.finditer(r"\.handle\(\s*", script):
            try:
                state, _ = decoder.raw_decode(script[boundary.end() :])
            except (ValueError, RecursionError):
                continue
            if not isinstance(state, dict) or not isinstance(state.get("instances"), list):
                continue
            for instance in state["instances"]:
                if (
                    not isinstance(instance, list)
                    or len(instance) < 3
                    or instance[1] != ["VideoConfig", "VideoPlayerHTML5Oz"]
                ):
                    continue
                args = instance[2]
                if not isinstance(args, list) or len(args) != 1 or not isinstance(args[0], dict):
                    raise _unavailable()
                config = args[0]
                data = config.get("videoData")
                if not isinstance(data, list) or len(data) != 1 or not isinstance(data[0], dict):
                    raise _unavailable()
                video = data[0]
                target = direct_video_target(video.get("video_url", ""))
                if (
                    target is None
                    or str(config.get("video_id")) != target[1]
                    or str(video.get("video_id")) != target[1]
                ):
                    raise _unavailable()
                if video.get("is_live_stream") or video.get("is_broadcast"):
                    raise _unavailable()
                targets.append(target)
    # Official post embeds render one primary post. Never take the first player
    # or unrelated anchor from a multi-player/ambiguous response.
    if not targets or len({identifier for _, identifier in targets}) != 1:
        raise _unavailable()
    return sorted(targets)[0][0]


def _unavailable() -> ProviderError:
    return ProviderError(
        "provider_response_changed",
        "Facebook returned unsupported public video metadata.",
        502,
        {"provider": "facebook"},
    )
