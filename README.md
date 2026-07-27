# Save It

Save It is a privacy-conscious media URL analysis experience built with Laravel 12 and PHP 8.2. The public application runs at `https://save.allmy.win`.

PR #3 refines the responsive landing experience, adds system-aware light and dark themes, introduces local platform brand glyphs, and hardens the production delivery pipeline. Actual media extraction and downloading are not implemented yet.

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
- Immutable app and Nginx images that share one Vite build artifact
- Production PHP error policy and GitHub Actions CI

Platform labels describe the current roadmap:

- YouTube and YouTube Shorts: MVP targets
- LinkedIn: Beta URL recognition
- Instagram, TikTok, X, and Facebook: analysis foundation; media engine support is planned

## Analyze endpoint

`POST /api/analyze` accepts JSON or a normal form request with a `url` field.

```bash
curl -X POST http://127.0.0.1:8099/api/analyze \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data '{"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ"}'
```

The endpoint:

- accepts only HTTP and HTTPS URLs;
- uses exact hostname matching to prevent suffix attacks;
- rejects embedded credentials, custom ports, localhost, and IP address URLs;
- does not resolve DNS, fetch submitted URLs, follow redirects, run shell commands, create jobs, or store history;
- returns normalized preview metadata with all download options marked unavailable;
- is limited to 30 requests per minute per client IP.

Supported normalized hosts include:

- `youtube.com`, `www.youtube.com`, `m.youtube.com`, `music.youtube.com`, `youtu.be`
- `instagram.com`, `www.instagram.com`
- `tiktok.com`, `www.tiktok.com`, `vm.tiktok.com`, `vt.tiktok.com`
- `x.com`, `www.x.com`, `twitter.com`, `www.twitter.com`
- `facebook.com`, `www.facebook.com`, `m.facebook.com`, `fb.watch`
- `linkedin.com`, `www.linkedin.com`

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

The first visit follows the browser or operating-system color preference. Choosing the iOS-style switch stores an explicit light or dark preference under the versioned key `save-it.theme.v1`. The adjacent System control removes that override and resumes following `prefers-color-scheme`.

Invalid or unavailable browser storage safely falls back to system mode. Theme state is applied before the main stylesheet to minimize a flash of the wrong theme.

## Current limitations

The following are intentionally not implemented in PR #3:

- yt-dlp, FFmpeg, or any other extraction engine
- functional media download links
- real duration, size, quality, creator, or channel metadata
- playlists and livestreams
- queue-backed analysis jobs
- server-side Recent Fetches persistence
- cross-browser or cross-device history synchronization

Planned YouTube outputs are MP4 with an H.264 compatibility preference, M4A, MP3 conversion, and thumbnail download. They remain disabled previews until the media engine is implemented.

## Technology and services

- Laravel 12
- PHP 8.2
- Blade, Vite, vanilla JavaScript, and CSS
- SQLite for initial application data
- Redis for cache, sessions, and queues
- Docker Compose services: `app`, `nginx`, `redis`, `worker`, and `scheduler`
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

Build the immutable app and Nginx images from the same source and frontend stage:

```bash
docker compose build app nginx
docker compose up -d
docker compose exec app php artisan migrate --force
```

For production, configure the untracked `.env` with:

```dotenv
APP_NAME="Save It"
APP_ENV=production
APP_DEBUG=false
```

Keep the generated `APP_KEY` private. Compose uses environment interpolation with production-safe defaults and does not contain an application key.

## Production delivery

The app and Nginx services are separate Dockerfile targets built from the same `frontend` stage:

- the app image reads its embedded Vite manifest;
- the Nginx image serves the matching embedded `public` directory and build assets;
- no host `public` bind mount or manual `docker cp` synchronization is required;
- Redis data and the host-mounted SQLite and Laravel runtime directories remain outside the images.

Build both targets together and recreate only the services that consume changed images:

```bash
docker compose build app nginx
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
npm audit --omit=dev
docker compose config
docker compose build app nginx
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

`.github/workflows/ci.yml` runs on pull requests and pushes to `main`. It validates Composer metadata, audits PHP and frontend dependencies, runs Laravel, Pint, and JavaScript tests, builds Vite assets, validates Docker Compose, builds both immutable image targets, and confirms their manifest hashes match.

## Brand icon notice

YouTube, YouTube Shorts, Instagram, TikTok, X, and Facebook glyph data come from the pinned [Simple Icons](https://simpleicons.org/) package under CC0-1.0. The local LinkedIn fallback uses the CC0 Simple Icons glyph shape retained for compatibility. Brand names and marks belong to their respective owners. Their presence describes URL recognition status and does not imply affiliation, endorsement, or partnership.

## AI-assisted development

This repository was built and maintained by [@mkaand](https://github.com/mkaand) with AI-assisted development support from
OpenAI ChatGPT and OpenAI Codex.

AI assistance was used for architecture planning, implementation support, debugging,
refactoring, documentation, and review. All final code decisions, validation,
deployment, and maintenance are handled by the repository owner.
