# Save It Extractor

This internal Python service defines the versioned extraction contract used by the
Save It Laravel application. It implements metadata-only extraction for public X and
Instagram posts, public YouTube videos and Shorts, and anonymous public LinkedIn
post analysis. Provider adapters return metadata and media references; binary
delivery remains in Laravel's separately validated delivery layer.

The production API is available only on the Docker network:

- `GET /health`
- `GET /ready`
- `POST /v1/extract`
- `POST /v1/youtube/resolve` (internal format resolution)

The X and Instagram adapters return normalized metadata and ordered media assets.
The YouTube adapter returns normalized video metadata, validated thumbnails, safe
format identifiers and ordered video/audio options. Laravel turns those identifiers
into short-lived direct, merge, M4A, and MP3 delivery plans.
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
Direct media URLs remain omitted from `/v1/extract`. The internal resolver accepts
only a canonical YouTube URL and a validated format identifier, reruns metadata
resolution, and returns only an HTTPS `*.googlevideo.com` source to Laravel.

The LinkedIn adapter accepts only canonical public post and activity URLs. It
uses verified TLS, ignores environment proxies, validates DNS and every redirect,
caps redirects and decompressed HTML, and accepts returned media references only
from `*.licdn.com`. It parses public Open Graph, Twitter Card, root/graph
`VideoObject`, and entity-decoded `video[data-sources]` metadata without credentials,
cookie persistence, browser automation, or login-wall bypass. Valid media metadata
takes precedence over generic login/join components; actual authwall redirects and
documents remain errors. Availability still depends on LinkedIn's unauthenticated
response.

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
