# Save It

Save It is a privacy-conscious media URL analysis experience built with Laravel 12 and PHP 8.2. The public application runs at `https://save.allmy.win`.

PR #2 adds the production landing experience, a safe URL analysis endpoint, planned output previews, and browser-local Recent Fetches. Actual media extraction and downloading are not implemented yet.

## Current capabilities

- Responsive, accessible Save It landing page
- Anonymous use with no accounts or authentication
- URL recognition for YouTube, YouTube Shorts, Instagram, TikTok, X/Twitter, Facebook, and LinkedIn
- Stable preview metadata and planned output options
- Deterministic YouTube thumbnails for validated 11-character video IDs
- Five browser-local Recent Fetches stored in `localStorage`
- Stateless JSON health endpoint
- Docker services for PHP-FPM, Nginx, Redis, the queue worker, and scheduler

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

## Current limitations

The following are intentionally not implemented in PR #2:

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

Start and prepare the Docker runtime:

```bash
docker compose build
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
