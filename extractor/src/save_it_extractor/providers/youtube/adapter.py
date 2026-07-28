from dataclasses import dataclass, field

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
from save_it_extractor.providers.youtube.client import YouTubeMetadataClient
from save_it_extractor.providers.youtube.parser import parse_youtube_metadata


@dataclass(frozen=True, slots=True)
class YouTubeProviderAdapter:
    provider: Provider = Provider.YOUTUBE
    client: YouTubeMetadataClient = field(default_factory=YouTubeMetadataClient)

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        payload = await self.client.fetch(context.normalized_url)
        metadata = parse_youtube_metadata(payload, context)

        return ExtractResult(
            request_id=request_id,
            provider=Provider.YOUTUBE,
            provider_label="YouTube",
            normalized_url=context.normalized_url,
            variant=context.variant,
            status="ready",
            media_type="short_video" if context.variant == "shorts" else "video",
            metadata=metadata,
            assets=[],
            capabilities=[
                "metadata",
                "thumbnails",
                "video_formats",
                "audio_formats",
                "conversion_plans",
            ],
        )
