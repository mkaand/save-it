from dataclasses import dataclass

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext


@dataclass(frozen=True, slots=True)
class StubProviderAdapter:
    provider: Provider

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        return ExtractResult(
            request_id=request_id,
            provider=context.provider,
            provider_label=context.provider_label,
            normalized_url=context.normalized_url,
            variant=context.variant,
        )
