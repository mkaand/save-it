# Save It Extractor

This internal Python service defines the versioned extraction contract used by the
Save It Laravel application. PR #4 provides URL validation, provider recognition,
stub adapters, structured logging, and controlled error responses. It does not fetch
submitted URLs or extract media.

The production API is available only on the Docker network:

- `GET /health`
- `GET /ready`
- `POST /v1/extract`

Provider adapters are intentionally stubs. Recognized providers return
`501 provider_not_implemented`, which Laravel maps to the existing public preview.

## Development

Use an isolated Python 3.12 environment:

```bash
python3.12 -m venv .venv
. .venv/bin/activate
python -m pip install --requirement requirements-dev.lock
PYTHONPATH=src pytest
ruff check src tests
ruff format --check src tests
```

The service does not perform outbound HTTP requests, DNS resolution, redirects, shell
commands, downloads, or conversion. Future provider implementations must add an
explicit outbound policy layer before any remote access is introduced.
