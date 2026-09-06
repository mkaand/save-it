from dataclasses import dataclass, field

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext
from save_it_extractor.domain.urls import classify_url
from save_it_extractor.providers.errors import ProviderError
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
        resolved_context = context
        if context.variant == "short":
            resolved_url = await self.client.resolve_short_link(context.normalized_url)
            resolved_context = classify_url(resolved_url)
            if (
                resolved_context.provider is not Provider.LINKEDIN
                or resolved_context.variant == "short"
            ):
                raise ProviderError(
                    "unsupported_url",
                    "LinkedIn Beta supports public post URLs only.",
                    422,
                    {"provider": "linkedin"},
                )
        document = await self.client.fetch(resolved_context.normalized_url)
        metadata, assets, media_type = parse_linkedin_document(
            document,
            resolved_context.normalized_url,
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
            normalized_url=resolved_context.normalized_url,
            variant=resolved_context.variant,
            status="ready",
            media_type=media_type,
            metadata=metadata,
            assets=assets,
            capabilities=capabilities,
            maturity="stable",
            warnings=LINKEDIN_WARNINGS,
        )
