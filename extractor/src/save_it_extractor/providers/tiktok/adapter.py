from dataclasses import dataclass, field
from urllib.parse import urlsplit, urlunsplit

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
from save_it_extractor.domain.urls import UrlValidationError, classify_url
from save_it_extractor.providers.errors import ProviderError
from save_it_extractor.providers.tiktok.network import TikTokMetadataClient
from save_it_extractor.providers.tiktok.parser import parse_tiktok_video


@dataclass(frozen=True, slots=True)
class TikTokProviderAdapter:
    provider: Provider = Provider.TIKTOK
    client: TikTokMetadataClient = field(default_factory=TikTokMetadataClient)

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        resolved = context
        if context.variant == "short":
            try:
                target = await self.client.resolve_short_link(context.normalized_url)
                parsed = urlsplit(target)
                # The redirect client has already pinned and verified every hop.
                # Drop server-supplied tracking/query material before strict URL parsing.
                resolved = classify_url(
                    urlunsplit(("https", "www.tiktok.com", parsed.path, "", ""))
                )
            except UrlValidationError as exc:
                raise ProviderError(
                    "unsupported_url",
                    "TikTok short links must resolve to a public video.",
                    422,
                    {"provider": "tiktok"},
                ) from exc
        if resolved.provider is not Provider.TIKTOK or resolved.variant != "video":
            raise ProviderError(
                "unsupported_url",
                "TikTok supports public video URLs only.",
                422,
                {"provider": "tiktok"},
            )
        parts = resolved.normalized_url.rstrip("/").split("/")
        username, video_id = parts[-3].lstrip("@"), parts[-1]
        metadata, assets, media_type = parse_tiktok_video(
            await self.client.fetch_page(resolved.normalized_url), video_id, username
        )
        return ExtractResult(
            request_id=request_id,
            provider=Provider.TIKTOK,
            provider_label="TikTok",
            normalized_url=resolved.normalized_url,
            variant="video",
            status="ready",
            media_type=media_type,
            metadata=metadata,
            assets=assets,
            capabilities=["metadata", "media_assets", "video_variants"],
            maturity="beta",
            warnings=["Public availability depends on TikTok's anonymous canonical response."],
        )
