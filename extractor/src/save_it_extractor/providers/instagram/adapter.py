from dataclasses import dataclass, field
from urllib.parse import urlsplit

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
from save_it_extractor.providers.instagram.network import InstagramMetadataClient
from save_it_extractor.providers.instagram.parser import parse_instagram_embed


@dataclass(frozen=True, slots=True)
class InstagramProviderAdapter:
    provider: Provider = Provider.INSTAGRAM
    client: InstagramMetadataClient = field(default_factory=InstagramMetadataClient)

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        parts = [part for part in urlsplit(context.normalized_url).path.split("/") if part]
        kind, shortcode = parts
        page = await self.client.fetch(kind, shortcode)
        metadata, assets, media_type = parse_instagram_embed(page, shortcode, kind)
        capabilities = ["metadata", "media_assets"]
        if len(assets) > 1:
            capabilities.append("multiple_assets")

        return ExtractResult(
            request_id=request_id,
            provider=Provider.INSTAGRAM,
            provider_label="Instagram",
            normalized_url=context.normalized_url,
            variant=context.variant,
            status="ready",
            media_type=media_type,
            metadata=metadata,
            assets=assets,
            capabilities=capabilities,
        )
