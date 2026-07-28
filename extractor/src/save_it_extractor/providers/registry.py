from save_it_extractor.domain.models import Provider
from save_it_extractor.providers.base import ProviderAdapter
from save_it_extractor.providers.stubs import StubProviderAdapter


class ProviderRegistry:
    def __init__(self, adapters: list[ProviderAdapter] | None = None) -> None:
        configured = adapters or [StubProviderAdapter(provider) for provider in Provider]
        self._adapters = {adapter.provider: adapter for adapter in configured}
        missing = set(Provider) - self._adapters.keys()
        if missing:
            names = ", ".join(sorted(provider.value for provider in missing))
            raise ValueError(f"Missing provider adapters: {names}")

    def get(self, provider: Provider) -> ProviderAdapter:
        return self._adapters[provider]

    @property
    def providers(self) -> frozenset[Provider]:
        return frozenset(self._adapters)
