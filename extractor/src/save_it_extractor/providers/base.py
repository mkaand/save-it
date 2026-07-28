from typing import Protocol

from save_it_extractor.domain.models import ExtractResult, Provider, ProviderContext


class ProviderAdapter(Protocol):
    provider: Provider

    async def extract(self, context: ProviderContext, request_id: str) -> ExtractResult:
        """Return a normalized extraction result without leaking provider internals."""
