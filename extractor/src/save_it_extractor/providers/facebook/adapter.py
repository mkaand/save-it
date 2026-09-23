from dataclasses import dataclass, field

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
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
        video_id = _video_id(context.normalized_url)
        metadata, assets, media_type = parse_facebook_video(
            await self.client.fetch_page(context.normalized_url), video_id
        )
        return ExtractResult(
            request_id=request_id,
            provider=Provider.FACEBOOK,
            provider_label="Facebook",
            normalized_url=context.normalized_url,
            variant=context.variant,
            status="ready",
            media_type=media_type,
            metadata=metadata,
            assets=assets,
            capabilities=["metadata", "media_assets", "video_variants"],
            maturity="beta",
            warnings=["Public availability depends on Facebook's anonymous Relay response."],
        )


def _video_id(url: str) -> str:
    if "?v=" in url:
        return url.split("?v=", 1)[1]
    return url.rstrip("/").split("/")[-1]
