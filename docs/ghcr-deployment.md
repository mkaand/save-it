# Optional GHCR deployment (production)

The default [installation guide](installation.md) continues to use local Docker
builds. This is an **opt-in** deployment path for operators who prefer immutable
published images and a VPS that does not build the application.

## Images and release policy

The `Publish immutable GHCR images` workflow runs only after the existing `CI`
workflow **succeeds on a push to `main`**. Failed PHP Composer audits, Python,
frontend, or Docker validation prevent publication. The workflow publishes
`linux/amd64` images, tagged with the full source commit SHA:

- `ghcr.io/mkaand/save-it-app:sha-<40-character-commit>`
- `ghcr.io/mkaand/save-it-nginx:sha-<40-character-commit>`
- `ghcr.io/mkaand/save-it-extractor:sha-<40-character-commit>`

The GitHub Actions release summary records each resulting immutable
`sha256:...` digest. Always use the **three digests from the same successful
workflow run**. There is no `latest` tag and no automatic production deployment.

The package owner must make the GHCR packages public if anonymous installations
are intended. Otherwise, perform a noninteractive, scoped registry login on the
deployment host before pulling images; never embed registry credentials in Compose.

## Preflight: never skip the recovery boundary

Before replacing a production container:

1. Verify the running source revision, cleanly distinguish any local Git changes,
   inspect the running and rollback images, and confirm the new digests resolve for
   the host's `linux/amd64` architecture.
2. Take **verified, restorable backups** of the live SQLite database (using the
   SQLite backup API or `sqlite3 .backup`), the complete `storage/` directory
   (including previews, prepared files, and settings-related artifacts), the
   untracked `.env`, and the `media-downloader-redis-data` named volume.
   Preserve permissions and store backups **outside** the application checkout.
   Redis's live data must be captured with a consistent Redis backup mechanism.
3. Confirm that the restored backup can be read without writing over live data.
4. Ensure there is an independently runnable rollback image or an isolated,
   tested rebuild of the previously accepted source. A running container whose
   image no longer exists in Docker's image store is **not a rollback image**.
   **Stop the deployment if rollback cannot be demonstrated.**
5. Check that the download queue has no job that will be interrupted by a worker
   restart. Production schema changes and key regeneration are out of scope.

## Compose overlay

Docker Compose 2.24.4 or newer is required for the `!reset` override in
[`docker-compose.ghcr.yml`](../docker-compose.ghcr.yml), which removes all
application `build:` declarations while preserving the original service
definitions, mounts, network, health checks, and Redis volume.

Make an **untracked**, mode `0600` `.env.ghcr` file in the checkout (the
repository's `.gitignore` already ignores `.env.*`):

```dotenv
SAVE_IT_APP_DIGEST=sha256:<published-app-digest-hex>
SAVE_IT_NGINX_DIGEST=sha256:<published-nginx-digest-hex>
SAVE_IT_EXTRACTOR_DIGEST=sha256:<published-extractor-digest-hex>
```

Do not replace or regenerate the existing `.env`. The two environment files are
needed for Compose variable interpolation; the base Compose service's `env_file`
continues to populate app runtime configuration from the existing `.env`.

Run commands from the application directory. Inspect the resolved image list
without displaying environment values:

```bash
docker compose --env-file .env --env-file .env.ghcr \
  -f docker-compose.yml -f docker-compose.ghcr.yml config --images
```

Confirm the app, worker and scheduler resolve to the **same digest**. Verify the
full resolved config only through a secure channel because it can contain
environment values. Check mount paths, service names and volume identity against
the currently running deployment.

Pull only the image-bearing application services:

```bash
docker compose --env-file .env --env-file .env.ghcr \
  -f docker-compose.yml -f docker-compose.ghcr.yml \
  pull app nginx extractor
```

Inspect the pulled digest/architecture, stage and test the rollback candidate,
and verify all backups **before** recreating anything.

## Controlled cutover

Use a maintenance window and keep Redis running. The five application services
must be updated in dependency-aware order; use the original queue worker's
graceful stop limit and ensure in-flight jobs are handled. Every `up` must use
`--no-build --no-deps` and only the intended service names. The operator should
inspect the resulting plan before executing any replacement.

**Never use** `docker compose down -v`, `docker volume prune`,
`docker system prune -a`, `git reset --hard`, automatic migrations, or
`php artisan key:generate` during this operation. Do not rebuild or replace
Redis merely to apply a monitoring label.

The optional overlay sets `diun.enable=false` on all six services as a
declarative default. A service that is not recreated retains its old runtime
labels until a future planned recreation. DIUN can still attempt image
inspection *before* applying its label filter, so an orphaned old image must be
replaced or restored for that specific inspect error to stop.

## Acceptance and rollback

Check `/`, `/health`, `/ready`, and `/admin/login` (all HTTP 200);
app/nginx/extractor health; running worker/scheduler; Redis `PONG`; restart
counts; `OOMKilled=false`; correct bind mounts and SQLite identity;
administration settings; analytics, preview retention, and media functionality.

Keep the previous rollback image, backup, and image digest manifest until the
new deployment has passed functional acceptance. If the deployment fails,
return to the tested rollback images without removing or overwriting
`.env`, `database/`, `storage/`, or `media-downloader-redis-data`.
If a schema migration occurred, reassess compatibility before rollback;
this optional GHCR cutover does **not** authorize a schema migration.
