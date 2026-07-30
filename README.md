# Save It

Save It is a privacy-conscious media URL analysis experience built with Laravel 12 and PHP 8.2. The public application runs at `https://save.allmy.win`.

PR #9 adds production LinkedIn public-media parsing, short-lived signed download
references, Range-aware proxy streaming, queue-backed YouTube merge and audio
conversion, and multi-asset ZIP preparation. Authenticated/private media remains out
of scope.

## Current capabilities

- Compact, mobile-first Save It landing page with balanced desktop layouts
- System-aware light and dark themes with a versioned browser preference
- Local platform brand glyphs with no runtime third-party requests
- Anonymous use with no accounts or authentication
- URL recognition for YouTube, YouTube Shorts, Instagram, TikTok, X/Twitter, Facebook, and LinkedIn
- Real metadata analysis for public YouTube videos and Shorts
- Validated YouTube thumbnails, ordered MP4/WebM video formats, and M4A/WebM audio formats
- Five browser-local Recent Fetches stored in `localStorage`
- Stateless JSON health endpoint
- Docker services for PHP-FPM, Nginx, Redis, the queue worker, and scheduler
- Internal Python 3.12 extractor service with FastAPI, Pydantic, and versioned API v1
- Exact-host provider registry with X, Instagram, YouTube, and LinkedIn adapters and controlled stubs for other providers
- X public post metadata, ordered assets, and source video variants
- Instagram public post/reel metadata and ordered image/video carousel assets
- Open Graph, Twitter Card, robots, sitemap, manifest, and local social-preview assets
- Optional production-only Umami analytics configured through untracked environment values
- Compact local GitHub link that identifies Save It as open source
- Canonical Save It SVG, favicons, Apple Touch Icon, and web app manifest
- Immutable app and Nginx images that share one Vite build artifact
- Production PHP error policy and GitHub Actions CI

Platform labels describe the current implementation:

- X: public media extraction and individual/ZIP delivery available
- Instagram: public post/reel extraction and individual/ZIP delivery available
- YouTube and YouTube Shorts: direct formats, MP4 merge, M4A, MP3, and thumbnails available
- LinkedIn: public post analysis and progressive public-media delivery available
- TikTok and Facebook: URL recognition; extraction is planned

## Analyze endpoint

`POST /api/analyze` accepts JSON or a normal form request with a `url` field.

```bash
curl -X POST http://127.0.0.1:8099/api/analyze \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data '{"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ"}'
```

The endpoint delegates authoritative provider recognition to the internal extractor:

- accepts only HTTP and HTTPS URLs;
- uses exact hostname matching to prevent suffix attacks;
- rejects embedded credentials, custom ports, localhost, and IP address URLs;
- does not fetch arbitrary submitted URLs or store server-side analysis history;
- permits X, Instagram, and LinkedIn adapters to fetch canonical, service-constructed metadata URLs;
- submits only strictly canonical YouTube video URLs to the pinned yt-dlp library API;
- returns real X, Instagram, YouTube, and LinkedIn metadata, short-lived Save It delivery references, or normalized previews for stubs;
- is limited to 30 requests per minute per client IP.

Laravel calls `POST http://extractor:8000/v1/extract` with explicit connect and total
timeouts and redirects disabled. X success responses are validated and mapped into a
limited public asset DTO. Recognized stubs return a controlled internal `501
provider_not_implemented`, which Laravel maps to a preview. Extractor or X upstream
failures become safe public errors, while an invalid extractor contract becomes a
safe `502`.

See [Extractor API v1](docs/extractor-api-v1.md) for the complete request, response,
error, versioning, and privacy contract.

Supported normalized hosts include:

- `youtube.com`, `www.youtube.com`, `m.youtube.com`, `music.youtube.com`, `youtu.be`
- `instagram.com`, `www.instagram.com`
- `tiktok.com`, `www.tiktok.com`, `vm.tiktok.com`, `vt.tiktok.com`
- `x.com`, `www.x.com`, `twitter.com`, `www.twitter.com`, `mobile.twitter.com`
- `facebook.com`, `www.facebook.com`, `m.facebook.com`, `fb.watch`
- `linkedin.com`, `www.linkedin.com`, `m.linkedin.com`

## Python extractor service

The `extractor` service runs Python 3.12 as UID/GID `10001`, has a read-only root
filesystem, drops all Linux capabilities, enables `no-new-privileges`, and receives
only a controlled `/tmp` tmpfs. It has no host port, Docker socket, privileged mode,
host network, or production source bind mount.

`POST /v1/extract` accepts metadata-only requests up to 8 KiB and URLs up to 2,048
characters. Pydantic rejects unknown fields. The provider registry classifies X,
Instagram, YouTube (including the Shorts subtype), TikTok, Facebook, and LinkedIn.
The X adapter accepts only canonical status paths, fetches bounded structured
metadata from `cdn.syndication.twimg.com`, and emits allowlisted
`pbs.twimg.com`/`video.twimg.com` asset references. The Instagram adapter accepts only
post and reel paths, fetches bounded public embed metadata from `www.instagram.com`,
and emits allowlisted `*.cdninstagram.com` asset references. The LinkedIn
adapter accepts only public post and activity URLs, reads bounded public structured
HTML metadata, and emits only allowlisted `*.licdn.com` asset references. TikTok and
Facebook remain `not_implemented` stubs. The YouTube adapter accepts only canonical
video and Shorts URLs and uses the pinned yt-dlp Python library API in metadata-only
mode.

The service uses these non-secret settings:

```dotenv
EXTRACTOR_BASE_URL=http://extractor:8000
EXTRACTOR_TIMEOUT_SECONDS=15
EXTRACTOR_CONNECT_TIMEOUT_SECONDS=2
EXTRACTOR_APP_NAME=save-it-extractor
EXTRACTOR_ENV=production
EXTRACTOR_LOG_LEVEL=INFO
EXTRACTOR_API_VERSION=1
EXTRACTOR_REQUEST_TIMEOUT_SECONDS=12
```

Laravel delivery settings are non-secret limits; signing continues to use the
existing untracked `APP_KEY`:

```dotenv
DOWNLOAD_TOKEN_TTL_SECONDS=600
DOWNLOAD_JOB_TTL_SECONDS=3600
DOWNLOAD_CONNECT_TIMEOUT_SECONDS=3
DOWNLOAD_TIMEOUT_SECONDS=120
DOWNLOAD_MAX_REDIRECTS=3
DOWNLOAD_MAX_FILE_BYTES=536870912
DOWNLOAD_MAX_ZIP_ASSETS=20
DOWNLOAD_MAX_ZIP_BYTES=1073741824
DOWNLOAD_JOB_TIMEOUT_SECONDS=900
```

Structured logs include correlation and timing fields but omit full URLs, query
strings, headers, cookies, and bodies. Provider clients use verified TLS, ignore proxy
environment variables, validate DNS results and each redirect target, limit
redirects and decompressed response size. The
YouTube client disables playlists, cookies, downloads, subtitles, remote components,
and environment proxies. It returns safe format identifiers rather than direct media
URLs. A separate internal YouTube format-resolution endpoint resolves a previously
validated canonical video plus format identifier at delivery time. It does not accept
arbitrary provider URLs, cookies, credentials, or command-line flags.

Run Python checks in an isolated Python 3.12 environment:

```bash
cd extractor
python3.12 -m venv .venv
. .venv/bin/activate
python -m pip install --requirement requirements-dev.lock
PYTHONPATH=src pytest
ruff check src tests
ruff format --check src tests
pip-audit --requirement requirements.lock --no-deps --disable-pip
```

Optional provider live checks are excluded from CI and must be enabled explicitly:

```bash
SAVE_IT_RUN_INSTAGRAM_LIVE_TESTS=1 PYTHONPATH=src pytest -m live \
  tests/test_instagram_provider.py
SAVE_IT_RUN_LINKEDIN_LIVE_TESTS=1 PYTHONPATH=src pytest -m live \
  tests/test_linkedin_provider.py
```

The checked-in provider tests otherwise use deterministic, synthetic metadata
fixtures and mock transports. No credentials, cookies, or private content are
required. PR #10 still owns centralized egress, DNS-rebinding, and redirect-chain
hardening beyond provider-specific baselines.

## LinkedIn public media analysis

LinkedIn accepts public `/posts/...` URLs and
`/feed/update/urn:li:activity:<id>/` URLs on the standard, `www`, and mobile hosts.
Tracking parameters and fragments are removed. Post URLs keep a meaningful canonical
post URL while activity URLs retain their activity form. Profiles, company
pages, jobs, articles, newsletters, general feeds, authentication pages, and
`lnkd.in` short links are rejected. Short-link redirects are intentionally not
resolved in this PR.

The adapter requests only the canonical LinkedIn page without credentials or
persistent cookies. It prefers Open Graph and Twitter Card metadata, then JSON-LD,
and returns only fields actually exposed publicly. Root and `@graph` `VideoObject`
metadata and validated `<video data-sources>` progressive MP4 variants are supported,
including deterministic resolution/bitrate ordering. Login components do not
override valid public post metadata; real authwall redirects and pages remain
controlled failures. No login bypass, browser automation, account cookie, or
third-party downloader service is used.

## YouTube analysis

The YouTube adapter accepts `youtube.com/watch?v=<video-id>` on the standard,
`www`, `m`, and `music` hosts, `youtu.be/<video-id>`, and
`youtube.com/shorts/<video-id>`. A video URL containing playlist parameters is
analyzed as one video and normalized without those parameters.

Playlist-only, live, scheduled live, private, authentication-required,
age-restricted, and DRM-protected content is rejected with a safe error. Video
formats are ordered as MP4/H.264, MP4/H.265, other MP4, then WebM alternatives.
Audio-only M4A options precede WebM alternatives. Each format may include codec,
resolution, FPS, bitrate, estimated size, and whether a separate audio stream must be
merged. Direct formats and M4A stream through short-lived delivery references.
Separate MP4 video/audio streams are prepared with FFmpeg stream copy. MP3 is
converted at 128, 192, 256, or 320 kbps without exposing the upstream source URL.

## Download delivery

Analyze results never accept an upstream URL back from the browser. Laravel stores a
versioned download plan in Redis and returns an opaque random identifier plus an
HMAC signature. Tokens expire after ten minutes by default and are revalidated
against provider-specific HTTPS host policy before each request.

`GET /api/downloads/{token}` streams direct media in 64 KiB chunks, forwards one
valid byte range, preserves `206`, `Content-Range`, `Accept-Ranges`, and `416`
semantics, bounds content size, disables buffering, sanitizes filenames, and closes
the upstream stream on disconnect. It forwards no cookies or Authorization headers.

`POST /api/download-jobs` atomically consumes one issued job token. Redis-backed workers use
unique private directories for:

- MP4 video plus M4A audio stream-copy merge;
- MP3 conversion at 128/192/256/320 kbps;
- fail-closed multi-asset ZIP creation with bounded asset count and aggregate size.

`GET /api/download-jobs/{id}` returns bounded `queued`, `processing`, `ready`, or
`failed` progress. Prepared files are served through another short-lived token and
deleted after delivery; the scheduler removes stale job directories. FFmpeg is
started with an argument array through `proc_open`, never a shell string, accepts
no user-provided flags, and is limited to two threads and a bounded output size.

See [Download delivery](docs/download-delivery.md) for token, Range, job, ZIP,
cleanup, and PR #10 boundary details.

## Recent Fetches

Recent Fetches use the versioned key `save-it.recent-fetches.v1`.

Only these normalized fields are stored:

- platform and platform label
- media type
- normalized URL
- safe fallback title
- optional author or organization
- optional thumbnail URL
- analysis timestamp

The newest five records are kept. Re-analyzing the same normalized URL moves it to the top. Data stays in the current browser, is not synchronized, and can be cleared from the page. If storage is unavailable or corrupt, analysis continues without browser history.

URL analysis itself is sent to the application server. The interface does not claim that server logs do not exist or promise absolute anonymity.

## Theme preference

The first visit follows the browser or operating-system color preference. Choosing the iOS-style switch stores an explicit light or dark preference under the versioned key `save-it.theme.v1`. The adjacent `A` Automatic control removes that override and resumes following `prefers-color-scheme`.

Invalid or unavailable browser storage safely falls back to system mode. Theme state is applied before the main stylesheet to minimize a flash of the wrong theme.

## Current limitations

The following remain intentionally out of scope after PR #9:

- playlists and livestreams
- queue-backed analysis jobs
- server-side Recent Fetches persistence
- cross-browser or cross-device history synchronization
- TikTok or Facebook extraction logic
- authenticated, private, authwall-protected, or short-link LinkedIn analysis
- Instagram Stories, Live, private, or login-required extraction
- X private/protected posts or authenticated extraction
- centralized PR #10 egress and DNS-rebinding controls
- authenticated/private provider content and automatic renewal of expired upstream assets

## Technology and services

- Laravel 12
- PHP 8.2
- Blade, Vite, vanilla JavaScript, and CSS
- SQLite for initial application data
- Redis for cache, sessions, and queues
- Docker Compose services: `app`, `nginx`, `redis`, `worker`, `scheduler`, and the
  internal-only `extractor`
- Python 3.12, FastAPI 0.140.7, Pydantic 2.13.4, HTTPX 0.28.1, Uvicorn 0.51.0, and yt-dlp 2026.7.4
- Simple Icons `16.27.1` as an exact development dependency for selected local brand glyphs

Nginx is published only on `127.0.0.1:8099`. Redis has no host port.

## Local setup

PHP 8.2 must be used explicitly:

```bash
cp .env.example .env
php8.2 "$(command -v composer)" install
php8.2 artisan key:generate
touch database/database.sqlite
npm install
npm run build
```

Generate the local favicon and web app icon set from the canonical SVG:

```bash
npm run generate:icons
npm run test:icons
```

Build the immutable app, Nginx, and extractor images:

```bash
docker compose build extractor app nginx
docker compose up -d
docker compose exec app php artisan migrate --force
```

For production, configure the untracked `.env` with:

```dotenv
APP_NAME="Save It"
APP_ENV=production
APP_DEBUG=false
UMAMI_ENABLED=true
UMAMI_SCRIPT_URL=https://stats.allmy.win/script.js
UMAMI_WEBSITE_ID=your-untracked-website-id
```

Keep the generated `APP_KEY` private. Compose uses environment interpolation with production-safe defaults and does not contain an application key.
Keep `UMAMI_WEBSITE_ID` untracked. Analytics render only when the application
environment is `production`, the feature is enabled, and the ID is present. A missing
or blocked analytics script never blocks the application.

## Public release files

- `LICENSE` contains the MIT license.
- `CONTRIBUTING.md` documents the pull-request and validation workflow.
- `public/robots.txt` allows indexing and points to `public/sitemap.xml`.
- `public/site.webmanifest`, local favicons, Apple Touch Icon, and PWA icons are
  generated from repository-owned assets.
- `resources/branding/save-it-social-card.svg` and the pinned local Inter package
  generate a path-only 1200×630 Open Graph/Twitter preview during
  `npm run generate:icons`. The current version is
  `/social/save-it-social-card-v2.png`; the original URL remains for compatibility.

The `.env.example` extractor URL points only to the internal Compose service. Do not
publish the extractor port or derive its base URL from submitted media URLs.

## Production delivery

The app and Nginx services are separate Dockerfile targets built from the same `frontend` stage:

- the app image reads its embedded Vite manifest;
- the Nginx image serves the matching embedded `public` directory and build assets;
- no host `public` bind mount or manual `docker cp` synchronization is required;
- Redis data and the host-mounted SQLite and Laravel runtime directories remain outside the images.

Build all changed targets together and recreate only the services that consume changed images:

```bash
docker compose build extractor app nginx
docker compose up -d --no-deps extractor
docker compose up -d --no-deps app
docker compose up -d --no-deps worker scheduler
docker compose up -d --no-deps nginx
```

Do not run `docker compose down` for a routine deployment. After deployment, validate the active HTML references, response MIME types, non-empty files, container files, and matching app/Nginx manifests:

```bash
./scripts/validate-assets.sh http://127.0.0.1:8099
./scripts/validate-assets.sh https://save.allmy.win
./scripts/validate-production-runtime.sh \
  /opt/media-downloader \
  http://127.0.0.1:8099 \
  https://save.allmy.win
```

The production validator requires five consecutive successful local and public
landing-page requests, safe health responses, writable Laravel logs, valid assets,
matching manifests, and persistent mount sources outside `/tmp`. Test app images
against empty storage/database mounts with
`./scripts/validate-runtime-init.sh media-downloader-app:local`.

Rollback checkouts and runtime mounts must use a persistent `/opt/...` directory.
Never leave app, worker, or scheduler attached to a source below `/tmp`. See the
[deployment and rollback runbook](docs/deployment-runbook.md) for the backup,
checksum, runtime initialization, controlled recreation, and acceptance process.

The production PHP configuration disables displayed errors and startup errors, logs all PHP errors to container stderr, disables HTML error output, and keeps `expose_php` off.

## Development and validation

```bash
php8.2 "$(command -v composer)" validate --strict
php8.2 artisan test
php8.2 vendor/bin/pint --test
php8.2 artisan route:list
php8.2 "$(command -v composer)" audit
npm run test:js
npm run build
npm run test:icons
npm audit --omit=dev
docker run --rm -v "$PWD/extractor:/work" -w /work python:3.12.12-slim-bookworm \
  sh -c "python -m venv /tmp/venv && /tmp/venv/bin/pip install -r requirements-dev.lock && PYTHONPATH=src /tmp/venv/bin/pytest"
docker compose config
docker compose build extractor app nginx
bash -n scripts/validate-assets.sh
bash -n scripts/validate-runtime-init.sh
bash -n scripts/validate-production-runtime.sh
./scripts/validate-runtime-init.sh media-downloader-app:local
```

Runtime checks:

```bash
docker compose ps
curl --fail http://127.0.0.1:8099/
curl --fail http://127.0.0.1:8099/health
```

The health response is stateless and contains only:

```json
{"status":"ok","application":"Save It"}
```

Cloudflare and Hestia configuration are managed outside this repository.

## Continuous integration

`.github/workflows/ci.yml` runs PHP, frontend, Python, and Docker jobs on pull
requests and pushes to `main`. It validates Composer metadata, dependency audits,
Laravel tests, Pint, JavaScript tests, icon generation, pytest, Ruff, the Python
runtime audit, Docker Compose, all changed images, and matching app/Nginx manifests.

## SEO, social previews, and analytics

The landing page publishes canonical, Open Graph, and Twitter Card metadata with the
versioned local 1200×630 Save It preview image. Its text is converted from the pinned
local Inter package to SVG glyph paths before Sharp renders the PNG, so CI and
container builds do not depend on system fonts. The same standards-based tags are suitable for
X, Facebook, LinkedIn, Telegram, WhatsApp, and Discord link unfurlers without a
runtime social API. `robots.txt`, `sitemap.xml`, the web app manifest, favicons, and
theme metadata are served locally.

Umami is optional and disabled by default. It is rendered only in Laravel's
`production` environment when `UMAMI_ENABLED=true`, the configured script URL
matches the approved analytics origin, and an untracked website ID is present. The
deferred analytics request is not required for application operation.

## Brand icon notice

YouTube, YouTube Shorts, Instagram, TikTok, X, Facebook, and the locally generated
GitHub glyph data come from the pinned [Simple Icons](https://simpleicons.org/)
package under CC0-1.0. The local LinkedIn fallback uses the CC0 Simple Icons glyph
shape retained for compatibility. Brand names and marks belong to their respective
owners. Their presence describes functionality or links to source code and does not
imply affiliation, endorsement, or partnership.

Inter is used only at build time to create deterministic social-card glyph paths.
The pinned `@fontsource/inter` package distributes Inter under the SIL Open Font
License 1.1.

## AI-assisted development

This repository was built and maintained by [@mkaand](https://github.com/mkaand) with AI-assisted development support from
OpenAI ChatGPT and OpenAI Codex.

AI assistance was used for architecture planning, implementation support, debugging,
refactoring, documentation, and review. All final code decisions, validation,
deployment, and maintenance are handled by the repository owner.
