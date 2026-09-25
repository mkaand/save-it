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
  Admin → Email offers **STARTTLS** (normally port 587; TLS is required, including
  when a server falls back to HELO), **Implicit TLS / SMTPS** (normally port 465),
  and **Plain SMTP** (no TLS, only for an explicitly trusted local relay).
  Certificate verification stays enabled for TLS. The runtime uses `smtp` or
  `smtps`, never the invalid `tls` transport scheme. Legacy `tls`/`ssl` settings
  map to STARTTLS/SMTPS; existing None/null settings retain their historical
  opportunistic TLS behavior and appear as **Automatic TLS (legacy setting)**.
  Select STARTTLS explicitly to require encryption. A blank password field
  preserves the encrypted stored password; secrets are never shown back.
  Failed test sends log only exception class, safe error category/message and
  numeric code, never SMTP replies, credentials, DSNs or transport transcripts.
- Turnstile is optional, disabled by default, and does not require a Cloudflare
  account for installations that leave it disabled.
- GeoIP defaults to `None`/`Unknown`. Operators may explicitly trust a country
  header or supply a local MaxMind GeoLite2 database path; Save It sends no visitor
  IP address to an external geolocation service.
- Reports may be Disabled, Daily, or Weekly. They require the Compose scheduler and
  a working SMTP configuration. Reports include an email-safe HTML dashboard and
  a plain-text alternative, with recorded operations, provider/country breakdowns,
  activity, top errors and a current queue/storage snapshot. Daily reports cover
  the last 24 hours; weekly reports cover the last 7 days, using existing hourly
  aggregate boundaries. Counts describe operations, not unique visitors or HTTP
  requests. Status badges reflect recorded errors/failed jobs, not provider uptime.
  No external images, scripts or tracking pixels are required. Table layouts and
  inline styles provide a readable fallback when an email client ignores rounded
  corners or mobile media queries. Scheduled-period duplicate prevention remains
  unchanged; Send report now is an explicit manual send.

Admin settings also expose provider enable/disable controls and Redis-backed rate
limit overrides. Aggregate analytics retain only time bucket, provider, operation,
success/error, safe error code, country code, and count—never raw IPs, URLs, tokens,
cookies, Authorization headers, or media content.
