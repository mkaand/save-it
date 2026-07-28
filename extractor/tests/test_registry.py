import asyncio

import pytest

from save_it_extractor.domain.models import Provider
from save_it_extractor.domain.urls import HOST_PROVIDERS, classify_url
from save_it_extractor.providers.registry import ProviderRegistry


def test_every_provider_has_an_adapter() -> None:
    registry = ProviderRegistry()

    assert registry.providers == frozenset(Provider)


@pytest.mark.parametrize(
    ("hostname", "provider"),
    [
        ("x.com", Provider.X),
        ("twitter.com", Provider.X),
        ("instagram.com", Provider.INSTAGRAM),
        ("youtube.com", Provider.YOUTUBE),
        ("youtu.be", Provider.YOUTUBE),
        ("tiktok.com", Provider.TIKTOK),
        ("facebook.com", Provider.FACEBOOK),
        ("fb.watch", Provider.FACEBOOK),
        ("linkedin.com", Provider.LINKEDIN),
    ],
)
def test_aliases_map_to_expected_provider(hostname: str, provider: Provider) -> None:
    assert HOST_PROVIDERS[hostname] is provider


def test_hostname_matching_is_exact() -> None:
    with pytest.raises(Exception) as exception:
        classify_url("https://notx.com/post")

    assert exception.value.code == "unsupported_host"


def test_every_stub_returns_controlled_empty_result() -> None:
    registry = ProviderRegistry()

    for provider in Provider:
        if provider in {Provider.X, Provider.INSTAGRAM, Provider.YOUTUBE}:
            continue
        context = classify_url(_example_url(provider))
        result = asyncio.run(registry.get(provider).extract(context, "registry-test"))
        assert result.status == "not_implemented"
        assert result.metadata is None
        assert result.assets == []
        assert result.capabilities == []


def test_x_uses_real_adapter() -> None:
    registry = ProviderRegistry()

    assert type(registry.get(Provider.X)).__name__ == "XProviderAdapter"


def test_youtube_uses_real_adapter() -> None:
    registry = ProviderRegistry()

    assert type(registry.get(Provider.YOUTUBE)).__name__ == "YouTubeProviderAdapter"


def _example_url(provider: Provider) -> str:
    return {
        Provider.X: "https://x.com/example/status/1",
        Provider.INSTAGRAM: "https://instagram.com/p/example/",
        Provider.YOUTUBE: "https://youtube.com/watch?v=abcdefghijk",
        Provider.TIKTOK: "https://tiktok.com/@example/video/1",
        Provider.FACEBOOK: "https://facebook.com/example/videos/1",
        Provider.LINKEDIN: "https://linkedin.com/posts/example",
    }[provider]
