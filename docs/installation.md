# Installation

Save It is a portable Laravel application. Docker Compose runs the application,
Nginx, Redis, queue worker, scheduler, and internal extractor without requiring a
specific reverse proxy, domain, cloud provider, or firewall product.

## Requirements

- Docker Engine with Compose v2
- a persistent directory for `database/` and `storage/`
- an application URL appropriate for the operator's deployment

## Fresh installation

```bash
git clone https://github.com/mkaand/save-it.git save-it
cd save-it
cp .env.example .env
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --force
docker compose run --rm --no-deps app php artisan migrate --force
docker compose up -d
```

The Compose file keeps SQLite and Laravel runtime storage in bind mounts, and Redis
in a named volume. Keep those locations persistent before running a production
instance. Verify the application after startup:

```bash
curl --fail http://localhost:8099/health
curl --fail http://localhost:8099/ready
docker compose ps
```

`/health` is a stateless liveness endpoint. `/ready` additionally checks the
configured database and shared cache/Redis dependency; neither endpoint probes the
internet or provider availability.

## First administrator

Save It never creates an automatic or default administrator. Create the single MVP
administrator interactively from the application container:

```bash
docker compose exec app php artisan save-it:admin:create
```

The command asks for a username, email, and hidden password confirmation. Do not put
the password in an environment variable or shell argument.

## Optional features

- SMTP is optional and enables password recovery and operational reports.
- Turnstile is optional, disabled by default, and does not require a Cloudflare
  account for installations that leave it disabled.
- GeoIP defaults to `None`/`Unknown`. Operators may explicitly trust a country
  header or supply a local MaxMind GeoLite2 database path; Save It sends no visitor
  IP address to an external geolocation service.
- Reports may be Disabled, Daily, or Weekly. They require the Compose scheduler and
  a working SMTP configuration.

Admin settings also expose provider enable/disable controls and Redis-backed rate
limit overrides. Aggregate analytics retain only time bucket, provider, operation,
success/error, safe error code, country code, and count—never raw IPs, URLs, tokens,
cookies, Authorization headers, or media content.
