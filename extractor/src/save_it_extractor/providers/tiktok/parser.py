"""Parse TikTok's exact canonical hydration script; never scrape arbitrary CDN URLs."""

import json
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urlsplit

from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.tiktok.network import is_allowed_asset_url


class _HydrationScriptParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=False)
        self._active = False
        self.content: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self._active = tag == "script" and dict(attrs).get("id") == "__UNIVERSAL_DATA_FOR_REHYDRATION__"

    def handle_endtag(self, tag: str) -> None:
        if tag == "script":
            self._active = False

    def handle_data(self, data: str) -> None:
        if self._active:
            self.content.append(data)


def parse_tiktok_video(html: str, expected_video_id: str, expected_username: str) -> tuple[dict, list[dict], str]:
    parser = _HydrationScriptParser()
    parser.feed(html)
    try:
        state = json.loads("".join(parser.content))
        item = state["__DEFAULT_SCOPE__"]["webapp.video-detail"]["itemInfo"]["itemStruct"]
    except (KeyError, TypeError, ValueError, json.JSONDecodeError) as exc:
        raise _changed() from exc
    if not isinstance(item, dict) or str(item.get("id")) != expected_video_id:
        raise _changed()
    author = item.get("author") if isinstance(item.get("author"), dict) else {}
    if str(author.get("uniqueId", "")).lower() != expected_username.lower():
        raise _changed()
    video = item.get("video") if isinstance(item.get("video"), dict) else {}
    selected, variants = _video_candidates(video)
    if selected is None:
        raise ProviderError("no_media", "The public TikTok post does not contain extractable video.", 422, {"provider": "tiktok"})
    cover = _first_allowed(video.get("cover"), video.get("originCover"), video.get("dynamicCover"))
    width = _positive(selected.get("width")) or _positive(video.get("width"))
    height = _positive(selected.get("height")) or _positive(video.get("height"))
    duration = _positive(video.get("duration"))
    asset = {
        "id": "asset-1", "order": 1, "type": "video", "role": "primary",
        "url": selected["url"], "thumbnail_url": cover, "mime_type": "video/mp4",
        "width": width, "height": height, "duration_ms": duration,
        "alt_text": None, "variants": variants,
    }
    metadata = {
        "post_id": expected_video_id, "text": _text(item.get("desc")),
        "author_name": _text(author.get("nickname"), 120),
        "author_handle": _text(author.get("uniqueId"), 64),
        "published_at": None, "thumbnail_url": cover, "media_count": 1,
    }
    return metadata, [asset], "video"


def _video_candidates(video: dict[str, Any]) -> tuple[dict | None, list[dict]]:
    raw: list[dict] = []
    values = video.get("bitrateInfo") if isinstance(video.get("bitrateInfo"), list) else []
    for index, value in enumerate(values):
        if not isinstance(value, dict):
            continue
        play = value.get("PlayAddr") if isinstance(value.get("PlayAddr"), dict) else value.get("playAddr")
        urls = play.get("UrlList") if isinstance(play, dict) else None
        url = next((candidate for candidate in urls if isinstance(candidate, str) and is_allowed_asset_url(candidate)), None) if isinstance(urls, list) else None
        if url:
            raw.append({"url": url, "width": _positive(value.get("width")), "height": _positive(value.get("height")), "bitrate": _positive(value.get("Bitrate")) or _positive(value.get("bitrate")), "index": index})
    direct = _first_allowed(video.get("playAddr"), video.get("downloadAddr"))
    if direct:
        raw.append({"url": direct, "width": _positive(video.get("width")), "height": _positive(video.get("height")), "bitrate": _positive(video.get("bitrate")), "index": len(raw)})
    raw.sort(key=lambda v: ((v["width"] or 0) * (v["height"] or 0), v["bitrate"] or 0, v["url"]), reverse=True)
    variants = [{"url": value["url"], "mime_type": "video/mp4", "protocol": "https", "bitrate": value["bitrate"], "width": value["width"], "height": value["height"], "fps": None, "container": "mp4", "quality_label": f"{value['width']}×{value['height']}" if value["width"] and value["height"] else "MP4", "filesize": None, "is_preferred": index == 0} for index, value in enumerate(raw[:8])]
    return (raw[0] if raw else None), variants


def _first_allowed(*values: Any) -> str | None:
    return next((value for value in values if isinstance(value, str) and is_allowed_asset_url(value)), None)


def _positive(value: Any) -> int | None:
    return value if isinstance(value, int) and value > 0 else None


def _text(value: Any, limit: int = 500) -> str | None:
    return " ".join(value.split())[:limit] or None if isinstance(value, str) else None


def _changed() -> ProviderError:
    return ProviderError("provider_response_changed", "TikTok returned unsupported public metadata.", 502, {"provider": "tiktok"})
