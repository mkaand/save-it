from save_it_extractor.domain.models import Provider
from save_it_extractor.providers.base import ProviderAdapter
from save_it_extractor.providers.instagram import InstagramProviderAdapter
from save_it_extractor.providers.stubs import StubProviderAdapter
from save_it_extractor.providers.x import XProviderAdapter
from save_it_extractor.providers.youtube import YouTubeProviderAdapter


class ProviderRegistry:
    def __init__(self, adapters: list[ProviderAdapter] | None = None) -> None:
        configured = adapters or [
            XProviderAdapter()
            if provider is Provider.X
            else (
                InstagramProviderAdapter()
                if provider is Provider.INSTAGRAM
                else (
                    YouTubeProviderAdapter()
                    if provider is Provider.YOUTUBE
                    else StubProviderAdapter(provider)
                )
            )
            for provider in Provider
        ]
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
