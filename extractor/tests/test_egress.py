import asyncio
import socket

import pytest

from save_it_extractor.providers.egress import (
    EgressPolicyError,
    is_public_address,
    resolve_allowed_url,
)


@pytest.mark.parametrize(
    "address",
    [
        "127.0.0.1",
        "10.0.0.1",
        "169.254.1.1",
        "224.0.0.1",
        "0.0.0.0",
        "::1",
        "fc00::1",
        "fe80::1",
        "ff00::1",
        "::",
        "::ffff:8.8.8.8",
    ],
)
def test_non_public_addresses_are_rejected(address: str) -> None:
    assert is_public_address(address) is False


def test_public_addresses_are_allowed() -> None:
    assert is_public_address("8.8.8.8") is True
    assert is_public_address("2606:4700:4700::1111") is True


def test_mixed_dns_answers_are_rejected(monkeypatch: pytest.MonkeyPatch) -> None:
    def answers(*_args, **_kwargs):
        return [
            (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("8.8.8.8", 443)),
            (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("127.0.0.1", 443)),
        ]

    monkeypatch.setattr(socket, "getaddrinfo", answers)
    with pytest.raises(EgressPolicyError, match="unsafe_address"):
        asyncio.run(
            resolve_allowed_url("https://metadata.example.test/path", {"metadata.example.test"})
        )


@pytest.mark.parametrize(
    "url",
    [
        "http://metadata.example.test/path",
        "https://user:pass@metadata.example.test/path",
        "https://metadata.example.test:8443/path",
        "https://metadata.example.test.evil.example/path",
    ],
)
def test_unsafe_url_shapes_are_rejected(url: str) -> None:
    with pytest.raises(EgressPolicyError, match="unsafe_url"):
        asyncio.run(resolve_allowed_url(url, {"metadata.example.test"}))
