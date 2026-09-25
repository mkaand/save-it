import asyncio
from dataclasses import dataclass, field
from urllib.parse import parse_qs, urlsplit

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.facebook.network import FacebookMetadataClient
from save_it_extractor.providers.facebook.parser import parse_facebook_video


@dataclass(frozen=True, slots=True)
class FacebookProviderAdapter:
    provider: Provider = Provider.FACEBOOK
    client: FacebookMetadataClient = field(default_factory=FacebookMetadataClient)

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        if context.variant not in {"video", "reel"}:
            raise ProviderError(
                "unsupported_url",
                "Facebook supports public video and Reel URLs only.",
                422,
                {"provider": "facebook"},
            )
        source = context.source_url or context.normalized_url
        # Keep shortlink query state until Facebook resolves it; only canonical
        # output is stripped. Classify again before using the original source.
        if classify_url(source).normalized_url != context.normalized_url:
            raise ProviderError(
                "unsupported_url", "Invalid Facebook content URL.", 422, {"provider": "facebook"}
            )
        page = await self.client.fetch_page(
            source if source.startswith("https://") else context.normalized_url
        )
        # Test clients may intentionally provide only HTML. Production clients
        # retain the validated terminal page URL after a bounded redirect chain.
        page_url = page.url if hasattr(page, "url") else context.normalized_url
        html = page.html if hasattr(page, "html") else page
        try:
            resolved = classify_url(page_url)
        except UrlValidationError as exc:
            raise ProviderError(
                "provider_response_changed",
                "Facebook returned unsupported public metadata.",
                502,
                {"provider": "facebook"},
            ) from exc
        video_id, story_id = _identities(resolved.normalized_url)
        metadata, assets, media_type = parse_facebook_video(
            html, expected_video_id=video_id, story_id=story_id
        )
        await self._enrich_native_renditions(assets)
        return ExtractResult(
            request_id=request_id,
            provider=Provider.FACEBOOK,
            provider_label="Facebook",
            normalized_url=resolved.normalized_url,
            variant=resolved.variant or "video",
            status="ready",
            media_type=media_type,
            metadata=metadata,
            assets=assets,
            capabilities=["metadata", "media_assets", "video_variants"],
            maturity="available",
            warnings=["Public availability depends on Facebook's anonymous Relay response."],
        )

    async def _enrich_native_renditions(self, assets: list[dict]) -> None:
        """Use bounded MP4 headers, not parent post dimensions, for labels."""
        if not hasattr(self.client, "probe_mp4"):
            for asset in assets:
                asset.pop("_facebook_audio", None)
            return
        for asset in assets:
            audio = asset.pop("_facebook_audio", None)
            variants = asset.get("variants")
            if not isinstance(variants, list):
                continue
            probes = await asyncio.gather(
                *(self.client.probe_mp4(variant["url"]) for variant in variants)
            )
            for variant, info in zip(variants, probes, strict=True):
                if not isinstance(variant, dict) or not isinstance(variant.get("url"), str):
                    continue
                if info is None:
                    if audio is not None:
                        raise ProviderError(
                            "provider_response_changed",
                            "Facebook rendition audio could not be verified safely.",
                            502,
                            {"provider": "facebook"},
                        )
                    continue
                if info.get("has_audio") is False and audio is not None:
                    variant["audio_url"] = audio
                width, height = info.get("width"), info.get("height")
                if not isinstance(width, int) or not isinstance(height, int):
                    continue
                variant["width"] = width
                variant["height"] = height
                native = (variant.get("quality_label") or "").split(" · ", 1)[0]
                native = native if native in {"HD", "SD"} else None
                bitrate = variant.get("bitrate")
                pieces = [native, f"{width}×{height}"]
                if isinstance(bitrate, int) and bitrate > 0:
                    pieces.append(f"{bitrate / 1_000_000:.1f} Mbps")
                variant["quality_label"] = " · ".join(piece for piece in pieces if piece)
            variants.sort(
                key=lambda item: (
                    -((item.get("width") or 0) * (item.get("height") or 0)),
                    -(item.get("bitrate") or 0),
                    item["url"].split("?", 1)[0],
                )
            )
            for index, variant in enumerate(variants):
                variant["is_preferred"] = index == 0
            asset["url"] = variants[0]["url"]
            asset["width"], asset["height"] = variants[0]["width"], variants[0]["height"]


def _identities(url: str) -> tuple[str | None, str | None]:
    parsed = urlsplit(url)
    values = parse_qs(parsed.query)
    if parsed.path == "/story.php":
        story = values.get("story_fbid", [])
        return None, story[0] if len(story) == 1 else None
    if parsed.path in {"/watch", "/watch/", "/video.php"}:
        values = values.get("v", [])
        return (values[0] if len(values) == 1 else None), None
    return parsed.path.rstrip("/").split("/")[-1], None
