# Save It Extractor

This internal Python service defines the versioned extraction contract used by the
Save It Laravel application. It implements metadata-only extraction for public X and
Instagram posts, public YouTube videos and Shorts, and best-effort anonymous
LinkedIn post analysis. It never downloads media binaries.

The production API is available only on the Docker network:

- `GET /health`
- `GET /ready`
- `POST /v1/extract`

The X and Instagram adapters return normalized metadata and ordered media assets.
The YouTube adapter returns normalized video metadata, validated thumbnails, safe
format identifiers, ordered video/audio options, and a disabled MP3 conversion plan.
TikTok and Facebook return `501 provider_not_implemented`, which Laravel maps
to the existing public preview.

The X adapter builds its own request to the public structured metadata host. It uses
verified TLS, ignores environment proxies, validates public DNS results and redirects,
caps redirects and decompressed response size, and accepts asset URLs only from
`pbs.twimg.com` and `video.twimg.com`. It does not retain cookies, invoke subprocesses,
or fetch the media URLs it returns.

The YouTube adapter uses pinned yt-dlp through its Python library API. It accepts only
canonical YouTube video and Shorts URLs, disables playlists, cookies, downloads,
subtitles, remote components, and environment proxies, and never invokes a shell.
Direct media URLs are intentionally omitted; delivery and stream merging remain PR
#9 scope.

The LinkedIn Beta adapter accepts only canonical public post and activity URLs. It
uses verified TLS, ignores environment proxies, validates DNS and every redirect,
caps redirects and decompressed HTML, and accepts returned media references only
from `*.licdn.com`. It parses public Open Graph, Twitter Card, and JSON-LD metadata
without credentials, cookie persistence, browser automation, or login-wall bypass.
Availability varies with LinkedIn's unauthenticated response.

## Development

Use an isolated Python 3.12 environment:

```bash
python3.12 -m venv .venv
. .venv/bin/activate
python -m pip install --requirement requirements-dev.lock
PYTHONPATH=src pytest
SAVE_IT_RUN_X_LIVE_TESTS=1 PYTHONPATH=src pytest -m live
SAVE_IT_RUN_LINKEDIN_LIVE_TESTS=1 PYTHONPATH=src pytest -m live \
  tests/test_linkedin_provider.py
ruff check src tests
ruff format --check src tests
```

The default suite is fixture-based and network-free. The optional live marker checks
one stable public X fixture and is not run by CI. X-specific egress is deliberately
narrow; centralized egress and DNS-rebinding hardening remain PR #10 scope.
