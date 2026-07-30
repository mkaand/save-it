from dataclasses import dataclass, field

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
from save_it_extractor.providers.linkedin.network import LinkedInMetadataClient
from save_it_extractor.providers.linkedin.parser import parse_linkedin_document

LINKEDIN_WARNINGS = [
    "Public availability depends on LinkedIn's current unauthenticated response.",
    "Posts that require signing in cannot be analyzed.",
]


@dataclass(frozen=True, slots=True)
class LinkedInProviderAdapter:
    provider: Provider = Provider.LINKEDIN
    client: LinkedInMetadataClient = field(default_factory=LinkedInMetadataClient)

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        document = await self.client.fetch(context.normalized_url)
        metadata, assets, media_type = parse_linkedin_document(
            document,
            context.normalized_url,
        )
        capabilities = ["metadata"]
        if assets:
            capabilities.append("media_assets")
        if len(assets) > 1:
            capabilities.append("multiple_assets")

        return ExtractResult(
            request_id=request_id,
            provider=Provider.LINKEDIN,
            provider_label="LinkedIn",
            normalized_url=context.normalized_url,
            variant=context.variant,
            status="ready",
            media_type=media_type,
            metadata=metadata,
            assets=assets,
            capabilities=capabilities,
            maturity="stable",
            warnings=LINKEDIN_WARNINGS,
        )
