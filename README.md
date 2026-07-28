# Save It

Save It is a privacy-conscious media URL analysis experience built with Laravel 12 and PHP 8.2. The public application runs at `https://save.allmy.win`.

PR #6 adds real, metadata-only extraction for public Instagram posts and reels while
retaining the public X extractor. It supports Instagram single images, videos,
reels, and ordered carousels. Media file delivery is not implemented yet.

## Current capabilities

- Compact, mobile-first Save It landing page with balanced desktop layouts
- System-aware light and dark themes with a versioned browser preference
- Local platform brand glyphs with no runtime third-party requests
- Anonymous use with no accounts or authentication
- URL recognition for YouTube, YouTube Shorts, Instagram, TikTok, X/Twitter, Facebook, and LinkedIn
- Stable preview metadata and planned output options
- Deterministic YouTube thumbnails for validated 11-character video IDs
- Five browser-local Recent Fetches stored in `localStorage`
- Stateless JSON health endpoint
- Docker services for PHP-FPM, Nginx, Redis, the queue worker, and scheduler
- Internal Python 3.12 extractor service with FastAPI, Pydantic, and versioned API v1
- Exact-host provider registry with X and Instagram adapters and controlled stubs for other providers
- X public post metadata, ordered assets, and source video variants
- Instagram public post/reel metadata and ordered image/video carousel assets
- Open Graph, Twitter Card, robots, sitemap, manifest, and local social-preview assets
- Optional production-only Umami analytics configured through untracked environment values
- Compact local GitHub link that identifies Save It as open source
- Canonical Save It SVG, favicons, Apple Touch Icon, and web app manifest
- Immutable app and Nginx images that share one Vite build artifact
- Production PHP error policy and GitHub Actions CI

Platform labels describe the current implementation:

- X: public media extraction available; download delivery remains planned
- Instagram: public post and reel extraction available; download delivery remains planned
- YouTube and YouTube Shorts: URL previews; extraction remains planned
- LinkedIn: Beta URL recognition
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
- does not fetch arbitrary submitted URLs, run shell commands, create jobs, or store history;
- permits X and Instagram adapters to fetch canonical, service-constructed metadata URLs;
- returns real X/Instagram metadata or normalized previews for stubs, with download controls unavailable;
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
- `linkedin.com`, `www.linkedin.com`

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
and emits allowlisted `*.cdninstagram.com` asset references. Other adapters remain
`not_implemented` stubs.

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

Structured logs include correlation and timing fields but omit full URLs, query
strings, headers, cookies, and bodies. Provider clients use verified TLS, ignore proxy
environment variables, validate DNS results and each redirect target, limit
redirects and decompressed response size, and do not fetch media binaries. The
service contains no shell invocation, yt-dlp, FFmpeg, or download path.

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
```

The checked-in Instagram tests otherwise use deterministic metadata fixtures and
mock transports. No credentials, cookies, or private content are required.

YouTube and LinkedIn remain planned for PRs #7 and #8. PR #10 still owns centralized egress,
DNS-rebinding, and redirect-chain hardening beyond the X-specific baseline.

## Recent Fetches

Recent Fetches use the versioned key `save-it.recent-fetches.v1`.

Only these normalized fields are stored:

- platform and platform label
- media type
- normalized URL
- safe fallback title
- optional thumbnail URL
- analysis timestamp

The newest five records are kept. Re-analyzing the same normalized URL moves it to the top. Data stays in the current browser, is not synchronized, and can be cleared from the page. If storage is unavailable or corrupt, analysis continues without browser history.

URL analysis itself is sent to the application server. The interface does not claim that server logs do not exist or promise absolute anonymity.

## Theme preference

The first visit follows the browser or operating-system color preference. Choosing the iOS-style switch stores an explicit light or dark preference under the versioned key `save-it.theme.v1`. The adjacent `A` Automatic control removes that override and resumes following `prefers-color-scheme`.

Invalid or unavailable browser storage safely falls back to system mode. Theme state is applied before the main stylesheet to minimize a flash of the wrong theme.

## Current limitations

The following are intentionally not implemented in PR #6:

- yt-dlp, FFmpeg, download, or conversion engines
- functional media download links
- real duration, size, quality, creator, or channel metadata
- playlists and livestreams
- queue-backed analysis jobs
- server-side Recent Fetches persistence
- cross-browser or cross-device history synchronization
- YouTube, TikTok, Facebook, or LinkedIn extraction logic
- Instagram Stories, Live, private, or login-required extraction
- X private/protected posts or authenticated extraction
- media file delivery, URL proxying, or expired asset URL renewal
- centralized PR #10 egress and DNS-rebinding controls

Planned YouTube outputs are MP4 with an H.264 compatibility preference, M4A, MP3 conversion, and thumbnail download. They remain disabled previews until the media engine is implemented.

## Technology and services

- Laravel 12
- PHP 8.2
- Blade, Vite, vanilla JavaScript, and CSS
- SQLite for initial application data
- Redis for cache, sessions, and queues
- Docker Compose services: `app`, `nginx`, `redis`, `worker`, `scheduler`, and the
  internal-only `extractor`
- Python 3.12, FastAPI 0.140.7, Pydantic 2.13.4, HTTPX 0.28.1, and Uvicorn 0.51.0
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
- `resources/branding/save-it-social-card.svg` generates the local 1200×630 Open
  Graph/Twitter preview image during `npm run generate:icons`.

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
```

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

The landing page publishes canonical, Open Graph, and Twitter Card metadata with a
local 1200×630 Save It preview image. The same standards-based tags are suitable for
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

## AI-assisted development

This repository was built and maintained by [@mkaand](https://github.com/mkaand) with AI-assisted development support from
OpenAI ChatGPT and OpenAI Codex.

AI assistance was used for architecture planning, implementation support, debugging,
refactoring, documentation, and review. All final code decisions, validation,
deployment, and maintenance are handled by the repository owner.
