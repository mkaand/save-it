# Media Downloader

Media Downloader is an English-language Laravel application intended to provide a safe foundation for future media retrieval workflows at `save.allmy.win`.

## PR #1 scope

PR #1 establishes Laravel 12, a minimal landing page, a safe JSON health endpoint, tests, and a Docker Compose runtime. Social media extraction, authentication, administration, yt-dlp, FFmpeg, Python services, Cloudflare, and production deployment are not implemented.

## Technology stack

- Laravel 12 on PHP 8.2
- Nginx
- SQLite for initial application data
- Redis for queues, cache, and sessions
- Docker Compose with application, web, worker, scheduler, and Redis services

PHP 8.2 is required. On the server, always invoke Composer and Artisan explicitly with PHP 8.2:

```bash
php8.2 "$(command -v composer)" install
php8.2 artisan --version
```

## Directory structure

```text
app/                 Application code
bootstrap/           Laravel bootstrap and runtime cache
config/              Laravel configuration
database/            Migrations and local SQLite database
docker/nginx/        Nginx virtual server used only by Compose
docker/php/          PHP 8.2 image, settings, and entrypoint
public/              Web root
resources/views/     Blade templates
routes/              Web and console routes
storage/             Runtime files and logs
tests/               Automated tests
```

## Docker services

- `app`: PHP 8.2 FPM application runtime
- `nginx`: local web server, bound only to `127.0.0.1:8099`
- `redis`: internal-only Redis with a persistent named volume
- `worker`: Redis-backed Laravel queue worker
- `scheduler`: Laravel scheduler process

Redis has no host port. The Compose network is private to this stack.

## Installation and startup

From `/opt/media-downloader`:

1. Create the local environment file:

   ```bash
   cp .env.example .env
   ```

2. Generate an application key with PHP 8.2:

   ```bash
   php8.2 artisan key:generate
   ```

   If the host dependencies are not installed, generate it through the built image:

   ```bash
   docker compose run --rm app php artisan key:generate
   ```

3. Create the SQLite file:

   ```bash
   touch database/database.sqlite
   chmod 660 database/database.sqlite
   ```

   The container entrypoint also creates the file when it is absent.

4. Build and start the containers:

   ```bash
   docker compose build
   docker compose up -d
   ```

5. Run migrations:

   ```bash
   docker compose exec app php artisan migrate --force
   ```

6. Open `http://127.0.0.1:8099`.

## Tests and health

Run host tests with PHP 8.2:

```bash
php8.2 artisan test
```

Or run them in the image, which includes SQLite:

```bash
docker compose run --rm app php artisan test
```

Inspect container health and application endpoints:

```bash
docker compose ps
docker inspect --format '{{json .State.Health}}' media-downloader-app
curl --fail http://127.0.0.1:8099/
curl --fail http://127.0.0.1:8099/health
```

The health endpoint returns only application availability and name:

```json
{"status":"ok","application":"Media Downloader"}
```

## Explicit exclusions

Social media extraction is not implemented in PR #1. Cloudflare configuration and public production deployment are out of scope. This Compose Nginx configuration does not alter Hestia or any production Nginx virtual host.
