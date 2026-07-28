from save_it_extractor.domain.models import ExtractResult
from save_it_extractor.domain.urls import classify_url
from save_it_extractor.providers.registry import ProviderRegistry


class ExtractionService:
    def __init__(self, registry: ProviderRegistry | None = None) -> None:
        self.registry = registry or ProviderRegistry()

    async def extract(self, url: str, request_id: str) -> ExtractResult:
        context = classify_url(url)
        adapter = self.registry.get(context.provider)
        return await adapter.extract(context, request_id)
