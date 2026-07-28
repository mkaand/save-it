from dataclasses import dataclass, field

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
from save_it_extractor.providers.x.network import XMetadataClient
from save_it_extractor.providers.x.parser import parse_x_payload


@dataclass(frozen=True, slots=True)
class XProviderAdapter:
    provider: Provider = Provider.X
    client: XMetadataClient = field(default_factory=XMetadataClient)

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        status_id = context.normalized_url.rsplit("/", 1)[-1]
        payload = await self.client.fetch(status_id)
        metadata, assets, media_type = parse_x_payload(payload, status_id)
        capabilities = ["metadata", "media_assets"]
        if any(asset["variants"] for asset in assets):
            capabilities.append("video_variants")
        if len(assets) > 1:
            capabilities.append("multiple_assets")

        return ExtractResult(
            request_id=request_id,
            provider=Provider.X,
            provider_label="X",
            normalized_url=context.normalized_url,
            variant=None,
            status="ready",
            media_type=media_type,
            metadata=metadata,
            assets=assets,
            capabilities=capabilities,
        )
