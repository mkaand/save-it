# Save It Extractor

This internal Python service defines the versioned extraction contract used by the
Save It Laravel application. PR #5 implements metadata-only extraction for public X
status URLs while retaining controlled stubs for every other provider. It never
downloads media binaries.

The production API is available only on the Docker network:

- `GET /health`
- `GET /ready`
- `POST /v1/extract`

The X adapter returns HTTP `200` with normalized metadata and ordered media assets.
Other recognized providers return `501 provider_not_implemented`, which Laravel maps
to the existing public preview.

The X adapter builds its own request to the public structured metadata host. It uses
verified TLS, ignores environment proxies, validates public DNS results and redirects,
caps redirects and decompressed response size, and accepts asset URLs only from
`pbs.twimg.com` and `video.twimg.com`. It does not retain cookies, invoke subprocesses,
or fetch the media URLs it returns.

## Development

Use an isolated Python 3.12 environment:

```bash
python3.12 -m venv .venv
. .venv/bin/activate
python -m pip install --requirement requirements-dev.lock
PYTHONPATH=src pytest
SAVE_IT_RUN_X_LIVE_TESTS=1 PYTHONPATH=src pytest -m live
ruff check src tests
ruff format --check src tests
```

The default suite is fixture-based and network-free. The optional live marker checks
one stable public X fixture and is not run by CI. X-specific egress is deliberately
narrow; centralized egress and DNS-rebinding hardening remain PR #10 scope.
