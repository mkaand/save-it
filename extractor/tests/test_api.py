import asyncio
import json
import socket
import subprocess
import urllib.request
from dataclasses import replace
from unittest.mock import AsyncMock

import pytest
from fastapi.testclient import TestClient

from save_it_extractor import main
from save_it_extractor.domain.models import ExtractResult, Provider
from save_it_extractor.domain.urls import classify_url

client = TestClient(main.app, raise_server_exceptions=False)


@pytest.mark.parametrize(
    ("url", "provider", "label", "variant"),
    [
        ("https://www.youtube.com/watch?v=abcdefghijk", "youtube", "YouTube", None),
        ("https://youtu.be/abcdefghijk", "youtube", "YouTube", None),
        (
            "https://www.youtube.com/shorts/abcdefghijk",
            "youtube",
            "YouTube",
            "shorts",
        ),
        ("https://www.tiktok.com/@example/video/123", "tiktok", "TikTok", None),
        ("https://www.facebook.com/example/videos/123", "facebook", "Facebook", None),
        ("https://www.linkedin.com/posts/example", "linkedin", "LinkedIn", None),
    ],
)
def test_recognized_providers_return_controlled_stub_contract(
    url: str, provider: str, label: str, variant: str | None
) -> None:
    response = client.post(
        "/v1/extract",
        json={
            "url": url,
            "request_id": "contract-test-1",
            "options": {"metadata_only": True},
        },
    )

    assert response.status_code == 501
    assert response.headers["x-request-id"] == "contract-test-1"
    error = response.json()["error"]
    assert error["code"] == "provider_not_implemented"
    assert error["request_id"] == "contract-test-1"
    assert error["details"]["provider"] == provider
    assert error["details"]["provider_label"] == label
    assert error["details"]["provider_variant"] == variant
    assert error["details"]["status"] == "not_implemented"
    assert error["details"]["media_type"] == "unknown"
    assert error["details"]["metadata"] is None
    assert error["details"]["assets"] == []
    assert error["details"]["capabilities"] == []


def test_health_is_safe() -> None:
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "service": "save-it-extractor",
        "version": "1",
    }
    serialized = response.text.lower()
    assert "secret" not in serialized
    assert "environment" not in serialized
    assert "app_key" not in serialized


def test_x_success_uses_versioned_data_contract(monkeypatch: pytest.MonkeyPatch) -> None:
    result = ExtractResult(
        request_id="contract-test-x",
        provider=Provider.X,
        provider_label="X",
        normalized_url="https://x.com/example/status/123",
        variant=None,
        status="ready",
        media_type="video",
        metadata={"post_id": "123", "media_count": 1},
        assets=[{"id": "asset-1", "type": "video", "order": 1}],
        capabilities=["metadata", "media_assets"],
    )
    monkeypatch.setattr(main.service, "extract", AsyncMock(return_value=result))

    response = client.post(
        "/v1/extract",
        json={
            "url": "https://x.com/example/status/123",
            "request_id": "contract-test-x",
        },
    )

    assert response.status_code == 200
    assert response.json()["data"] == {
        "request_id": "contract-test-x",
        "provider": "x",
        "provider_label": "X",
        "provider_variant": None,
        "media_type": "video",
        "source_url": "https://x.com/example/status/123",
        "normalized_url": "https://x.com/example/status/123",
        "status": "ready",
        "metadata": {"post_id": "123", "media_count": 1},
        "assets": [{"id": "asset-1", "type": "video", "order": 1}],
        "capabilities": ["metadata", "media_assets"],
    }
    assert "traceback" not in response.text.lower()


def test_readiness_is_safe() -> None:
    response = client.get("/ready")

    assert response.status_code == 200
    assert response.json()["status"] == "ready"


@pytest.mark.parametrize(
    ("payload", "code", "status"),
    [
        ({}, "validation_error", 400),
        ({"url": "not-a-url"}, "unsupported_scheme", 422),
        ({"url": "ftp://x.com/file"}, "unsupported_scheme", 422),
        ({"url": "https://example.com/file"}, "unsupported_host", 422),
        ({"url": "https://youtube.com.attacker.example/watch"}, "unsupported_host", 422),
        ({"url": "https://user:password@x.com/example/status/1"}, "embedded_credentials", 422),
        ({"url": "https://x.com:8443/example/status/1"}, "disallowed_port", 422),
        ({"url": "https://x.com/example"}, "invalid_x_post_url", 422),
        ({"url": "https://x.com/example/status/not-a-number"}, "invalid_x_post_url", 422),
        ({"url": "http://localhost/post"}, "unsupported_host", 422),
        ({"url": "http://127.0.0.1/post"}, "unsupported_host", 422),
        ({"url": "http://10.20.30.40/post"}, "unsupported_host", 422),
        ({"url": "http://169.254.10.20/post"}, "unsupported_host", 422),
        ({"url": "http://[::1]/post"}, "unsupported_host", 422),
        ({"url": "http://[fc00::1]/post"}, "unsupported_host", 422),
        ({"url": "http://[fe80::1]/post"}, "unsupported_host", 422),
        (
            {"url": "https://x.com/example/status/1", "request_id": "spaces are unsafe"},
            "validation_error",
            400,
        ),
        (
            {"url": "https://x.com/example/status/1", "unexpected": True},
            "validation_error",
            400,
        ),
        (
            {"url": "https://x.com/example/status/1", "options": {"metadata_only": False}},
            "validation_error",
            400,
        ),
    ],
)
def test_request_validation(payload: dict, code: str, status: int) -> None:
    response = client.post("/v1/extract", json=payload)

    assert response.status_code == status
    assert response.json()["error"]["code"] == code
    assert "traceback" not in response.text.lower()


def test_oversized_url_has_specific_error() -> None:
    response = client.post("/v1/extract", json={"url": f"https://x.com/{'a' * 2048}"})

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "url_too_long"


def test_oversized_request_body_is_rejected() -> None:
    body = json.dumps({"url": "https://x.com/post", "padding": "a" * 9000})
    response = client.post(
        "/v1/extract",
        content=body,
        headers={"content-type": "application/json"},
    )

    assert response.status_code == 413
    assert response.json()["error"]["code"] == "request_too_large"


def test_fragment_is_removed_and_query_is_preserved() -> None:
    normalized = classify_url("HTTPS://WWW.X.COM/example/status/123/photo/1?ref=safe#fragment")
    assert normalized.normalized_url == "https://x.com/example/status/123"


def test_server_generates_safe_request_id() -> None:
    response = client.post(
        "/v1/extract",
        json={"url": "https://www.tiktok.com/@example/video/123"},
    )
    request_id = response.json()["error"]["request_id"]

    assert response.headers["x-request-id"] == request_id
    assert 1 <= len(request_id) <= 64


def test_contract_never_invokes_network_dns_or_shell(monkeypatch: pytest.MonkeyPatch) -> None:
    def forbidden(*_args, **_kwargs):
        raise AssertionError("Outbound operations are forbidden in the contract service")

    monkeypatch.setattr(socket, "getaddrinfo", forbidden)
    monkeypatch.setattr(subprocess, "run", forbidden)
    monkeypatch.setattr(urllib.request, "urlopen", forbidden)

    response = client.post(
        "/v1/extract",
        json={"url": "https://www.tiktok.com/@example/video/123"},
    )

    assert response.status_code == 501
    assert response.json()["error"]["code"] == "provider_not_implemented"


def test_internal_error_response_does_not_expose_exception(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(
        main.service,
        "extract",
        AsyncMock(side_effect=RuntimeError("sensitive internal detail")),
    )
    response = client.post("/v1/extract", json={"url": "https://x.com/post"})

    assert response.status_code == 500
    assert response.json()["error"]["code"] == "internal_error"
    assert "sensitive internal detail" not in response.text
    assert "traceback" not in response.text.lower()


def test_processing_deadline_returns_safe_503(monkeypatch: pytest.MonkeyPatch) -> None:
    async def slow_extract(*_args, **_kwargs):
        await asyncio.sleep(0.02)

    monkeypatch.setattr(main.service, "extract", slow_extract)
    monkeypatch.setattr(
        main,
        "settings",
        replace(main.settings, request_timeout_seconds=0.001),
    )

    response = client.post("/v1/extract", json={"url": "https://x.com/post"})

    assert response.status_code == 503
    assert response.json()["error"]["code"] == "upstream_unavailable"
    assert "traceback" not in response.text.lower()
