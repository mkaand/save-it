"""Identity-bound parser for Facebook's public Relay video state and DASH metadata."""

import json
import xml.etree.ElementTree as ET
from html import unescape
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urlsplit

from save_it_extractor.config import settings
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.facebook.network import is_allowed_asset_url


class _DataSjsParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=False)
        self._active = False
        self.scripts: list[str] = []
        self._current: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self._active = tag == "script" and "data-sjs" in dict(attrs)
        if self._active:
            self._current = []

    def handle_data(self, data: str) -> None:
        if self._active:
            self._current.append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag == "script" and self._active:
            self.scripts.append("".join(self._current))
            self._active = False


def parse_facebook_video(
    html: str, expected_video_id: str | None = None, story_id: str | None = None
) -> tuple[dict, list[dict], str]:
    parser = _DataSjsParser()
    parser.feed(html)
    candidates: list[dict[str, Any]] = []
    for script in parser.scripts:
        try:
            state = json.loads(script)
            candidates.extend(
                _story_candidates(state, story_id)
                if story_id is not None
                else _relay_candidates(state, expected_video_id or "")
            )
        except json.JSONDecodeError:
            continue
    unique = _unique_candidates(candidates, expected_video_id)
    if len(unique) != 1:
        raise _changed()
    video = unique[0]
    variants = _variants(video)
    if not variants:
        raise ProviderError(
            "no_media",
            "The public Facebook post does not contain extractable video.",
            422,
            {"provider": "facebook"},
        )
    poster = _poster(video)
    selected = variants[0]
    # Preserve Facebook's own mute decision. Never restore audio from another
    # rendition/source when any muted interval is declared.
    muted = video.get("audio_availability") == "AVAILABLE_BUT_MUTED" or bool(
        video.get("muted_segments")
    )
    audio = sorted(
        (item for item in _dash_variants(video) if item["kind"] == "audio"),
        key=lambda item: (-(item["bitrate"] or 0), _media_identity(item["url"])),
    )
    metadata = {
        "post_id": story_id or expected_video_id,
        "text": _text(_first_string(video, "title", "name")),
        "author_name": _owner_name(video),
        "author_handle": None,
        "published_at": None,
        "thumbnail_url": poster,
        "media_count": 1,
    }
    asset = {
        "id": "asset-1",
        "order": 1,
        "type": "video",
        "role": "primary",
        "url": selected["url"],
        "thumbnail_url": poster,
        "mime_type": "video/mp4",
        # Post dimensions are intentionally never copied into rendition metadata.
        "width": selected["width"],
        "height": selected["height"],
        "duration_ms": _duration_ms(video),
        "alt_text": None,
        "_facebook_audio": None if muted or not audio else audio[0]["url"],
        "variants": [
            {
                "url": item["url"],
                "mime_type": "video/mp4",
                "protocol": "https",
                "bitrate": item["bitrate"],
                "width": item["width"],
                "height": item["height"],
                "fps": None,
                "container": "mp4",
                "quality_label": item["quality_label"],
                "filesize": None,
                "is_preferred": index == 0,
            }
            for index, item in enumerate(variants)
        ],
    }
    return metadata, [asset], "video"


def _relay_candidates(state: Any, expected_video_id: str) -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []

    def visit(value: Any) -> None:
        if isinstance(value, dict):
            if _identity(value, expected_video_id) and _has_delivery(value):
                candidates.append(value)
            for child in value.values():
                visit(child)
        elif isinstance(value, list):
            for child in value:
                visit(child)

    visit(state)
    return candidates


def _story_candidates(state: Any, story_id: str) -> list[dict[str, Any]]:
    """Select only a video nested in the requested story attachment tree.

    Relay documents include recommended/feed media alongside the primary story;
    discovering a delivery object globally would make a story URL ambiguous.
    """
    candidates: list[dict[str, Any]] = []
    attachment_keys = {
        "attachments",
        "attachment",
        "subattachments",
        "media",
        "creation_story",
        "short_form_video_context",
        "playback_video",
        "comet_sections",
        "content",
        "story",
        "styles",
        "style_type_renderer",
        "edges",
        "node",
    }

    def attachment_walk(value: Any, allowed: bool = False) -> None:
        if isinstance(value, dict):
            if allowed and _has_delivery(value):
                candidates.append(value)
            for key, child in value.items():
                if key in attachment_keys:
                    attachment_walk(
                        child,
                        allowed or key in {"attachments", "attachment", "media", "playback_video"},
                    )
        elif isinstance(value, list):
            for child in value:
                attachment_walk(child, allowed)

    def find_story(value: Any) -> None:
        if isinstance(value, dict):
            identifiers = {str(value.get(key) or "") for key in ("id", "story_fbid", "storyID")}
            if story_id in identifiers:
                attachment_walk(value)
            for child in value.values():
                find_story(child)
        elif isinstance(value, list):
            for child in value:
                find_story(child)

    find_story(state)
    return candidates


def _has_delivery(video: dict[str, Any]) -> bool:
    return isinstance(video.get("videoDeliveryLegacyFields"), dict) or isinstance(
        video.get("videoDeliveryResponseFragment"), dict
    )


def _identity(video: dict[str, Any], expected_video_id: str) -> bool:
    return str(video.get("videoId") or video.get("id") or "") == expected_video_id


def _unique_candidates(
    candidates: list[dict[str, Any]], expected_video_id: str | None
) -> list[dict[str, Any]]:
    unique: dict[str, dict[str, Any]] = {}
    for candidate in candidates:
        if expected_video_id is not None and not _identity(candidate, expected_video_id):
            continue
        identities = tuple(sorted(_media_identity(item["url"]) for item in _variants(candidate)))
        if identities:
            unique.setdefault("|".join(identities), candidate)
    return list(unique.values())


def _variants(video: dict[str, Any]) -> list[dict[str, Any]]:
    dash = _dash_variants(video)
    dash_by_identity = {
        _media_identity(item["url"]): item for item in dash if item["kind"] == "video"
    }
    raw: list[dict[str, Any]] = []
    legacy = video.get("videoDeliveryLegacyFields")
    if isinstance(legacy, dict):
        for key, rank, native_quality in (
            ("browser_native_hd_url", 2, "HD"),
            ("playable_url_quality_hd", 2, "HD"),
            ("browser_native_sd_url", 1, "SD"),
            ("playable_url", 1, "SD"),
        ):
            url = legacy.get(key)
            if isinstance(url, str) and is_allowed_asset_url(url):
                raw.append(
                    _enrich_native(_candidate(url, None, rank, native_quality), dash_by_identity)
                )
    response = video.get("videoDeliveryResponseFragment")
    result = response.get("videoDeliveryResponseResult") if isinstance(response, dict) else None
    progressive = result.get("progressive_urls") if isinstance(result, dict) else None
    if isinstance(progressive, list):
        for item in progressive:
            if (
                isinstance(item, dict)
                and isinstance(item.get("progressive_url"), str)
                and is_allowed_asset_url(item["progressive_url"])
            ):
                metadata = item.get("metadata") if isinstance(item.get("metadata"), dict) else None
                raw.append(
                    _enrich_native(
                        _candidate(item["progressive_url"], metadata, 0, None), dash_by_identity
                    )
                )

    # DASH is used to enrich native renditions and pair missing audio, never to
    # expose unpaired video-only representations as ordinary MP4 downloads.

    deduplicated: dict[str, dict[str, Any]] = {}
    for item in raw:
        identity = _media_identity(item["url"])
        deduplicated[identity] = (
            item if identity not in deduplicated else _merge_candidate(deduplicated[identity], item)
        )
    return sorted(deduplicated.values(), key=_rank)[: settings.facebook_max_variants]


def _dash_variants(video: dict[str, Any]) -> list[dict[str, Any]]:
    legacy = video.get("videoDeliveryLegacyFields")
    manifest = legacy.get("dash_manifest_xml_string") if isinstance(legacy, dict) else None
    if not isinstance(manifest, str) or not manifest or len(manifest) > 256_000:
        return []
    for _ in range(2):
        if manifest.lstrip().startswith("&lt;"):
            manifest = unescape(manifest)
    if "<!DOCTYPE" in manifest.upper() or "<!ENTITY" in manifest.upper():
        return []
    try:
        root = ET.fromstring(manifest)
    except ET.ParseError:
        return []
    if root.tag not in {"MPD", "{urn:mpeg:dash:schema:mpd:2011}MPD"}:
        return []
    variants: list[dict[str, Any]] = []
    for adaptation in root.iter():
        if _local(adaptation.tag) != "AdaptationSet":
            continue
        mime = str(adaptation.attrib.get("mimeType") or adaptation.attrib.get("contentType") or "")
        adaptation_base = _child_text(adaptation, "BaseURL")
        for representation in adaptation:
            if _local(representation.tag) != "Representation":
                continue
            url = _child_text(representation, "BaseURL") or adaptation_base
            if not isinstance(url, str) or not is_allowed_asset_url(url):
                continue
            codec = str(
                representation.attrib.get("codecs") or adaptation.attrib.get("codecs") or ""
            )
            kind = "audio" if mime in {"audio", "audio/mp4"} else "video"
            if kind == "audio" and (
                not codec.startswith("mp4a") or mime not in {"audio", "audio/mp4"}
            ):
                continue
            variants.append(
                {
                    "kind": kind,
                    "url": url,
                    "codec": codec,
                    "width": _positive_attribute(representation, "width"),
                    "height": _positive_attribute(representation, "height"),
                    "bitrate": _positive_attribute(representation, "bandwidth"),
                }
            )
    return variants


def _enrich_native(
    candidate: dict[str, Any], dash_by_identity: dict[str, dict[str, Any]]
) -> dict[str, Any]:
    dash = dash_by_identity.get(_media_identity(candidate["url"]))
    return (
        candidate
        if dash is None
        else _merge_candidate(
            candidate,
            _candidate(dash["url"], dash, candidate["quality_rank"], candidate["native_quality"]),
        )
    )


def _merge_candidate(left: dict[str, Any], right: dict[str, Any]) -> dict[str, Any]:
    merged = {
        **left,
        "width": left["width"] or right["width"],
        "height": left["height"] or right["height"],
        "bitrate": max(left["bitrate"] or 0, right["bitrate"] or 0) or None,
        "quality_rank": max(left["quality_rank"], right["quality_rank"]),
        "native_quality": left["native_quality"] or right["native_quality"],
    }
    merged["quality_label"] = _quality_label(
        merged["width"], merged["height"], merged["bitrate"], merged["native_quality"]
    )
    return merged


def _candidate(
    url: str, metadata: dict[str, Any] | None, quality_rank: int, native_quality: str | None
) -> dict[str, Any]:
    metadata = metadata or {}
    width, height, bitrate = (
        _positive(metadata.get("width")),
        _positive(metadata.get("height")),
        _positive(metadata.get("bitrate")),
    )
    return {
        "url": url,
        "width": width,
        "height": height,
        "bitrate": bitrate,
        "quality_rank": quality_rank,
        "native_quality": native_quality,
        "quality_label": _quality_label(width, height, bitrate, native_quality),
    }


def _rank(value: dict[str, Any]) -> tuple[int, int, int, str]:
    return (
        -((value["width"] or 0) * (value["height"] or 0)),
        -(value["bitrate"] or 0),
        -value["quality_rank"],
        _media_identity(value["url"]),
    )


def _local(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def _child_text(element: ET.Element, name: str) -> str | None:
    return next(
        (
            child.text
            for child in element
            if _local(child.tag) == name and isinstance(child.text, str)
        ),
        None,
    )


def _positive_attribute(element: ET.Element, name: str) -> int | None:
    value = element.attrib.get(name)
    return int(value) if isinstance(value, str) and value.isdigit() and int(value) > 0 else None


def _media_identity(url: str) -> str:
    return urlsplit(url).path


def _poster(video: dict[str, Any]) -> str | None:
    thumbnail = video.get("preferred_thumbnail")
    image = thumbnail.get("image") if isinstance(thumbnail, dict) else None
    url = image.get("uri") if isinstance(image, dict) else None
    return url if isinstance(url, str) and is_allowed_asset_url(url) else None


def _duration_ms(video: dict[str, Any]) -> int | None:
    duration = video.get("playable_duration_in_ms")
    if isinstance(duration, (int, float)) and duration > 0:
        return int(duration)
    duration = video.get("playable_duration")
    return int(duration * 1000) if isinstance(duration, (int, float)) and duration > 0 else None


def _owner_name(video: dict[str, Any]) -> str | None:
    owner = video.get("owner")
    return _text(_first_string(owner, "name"), 120) if isinstance(owner, dict) else None


def _first_string(values: dict[str, Any], *keys: str) -> str | None:
    return next((value for key in keys if isinstance((value := values.get(key)), str)), None)


def _positive(value: Any) -> int | None:
    return value if isinstance(value, int) and value > 0 else None


def _text(value: Any, limit: int = 500) -> str | None:
    return " ".join(value.split())[:limit] or None if isinstance(value, str) else None


def _quality_label(
    width: int | None, height: int | None, bitrate: int | None, native_quality: str | None
) -> str:
    dimensions = f"{width}×{height}" if width and height else None
    bitrate_label = f"{bitrate / 1_000_000:.1f} Mbps" if bitrate else None
    return (
        " · ".join(value for value in (native_quality, dimensions, bitrate_label) if value) or "MP4"
    )


def _changed() -> ProviderError:
    return ProviderError(
        "provider_response_changed",
        "Facebook returned unsupported public video metadata.",
        502,
        {"provider": "facebook"},
    )
