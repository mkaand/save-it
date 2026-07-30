# Deployment and rollback runbook

## Safety boundaries

Production deployments must use a clean, merged `main` checkout. Do not deploy a
feature branch, run `docker compose down`, recreate Redis, delete volumes, or run a
global Docker cleanup.

Before changing a container:

1. record the current Git commit, container IDs, image IDs, health, restart counts,
   OOM state, mount sources, SQLite checksum, and `.env` checksum;
2. create a timestamped backup outside the repository;
3. verify the backup checksum manifest;
4. confirm that every long-lived bind mount source is under a persistent path such
   as `/opt`, never `/tmp`;
5. prepare a rollback image or a reproducible checkout of the previous commit.

## Laravel runtime directories

The app image contains `/usr/local/bin/media-downloader-runtime-init`. Every app,
worker, and scheduler startup recreates the required writable directory structure
when a bind mount or empty volume hides the image contents:

- `storage/app/private`
- `storage/app/public`
- `storage/framework/cache/data`
- `storage/framework/sessions`
- `storage/framework/testing`
- `storage/framework/views`
- `storage/logs`
- `bootstrap/cache`

Initialization is idempotent, applies `0775` directory permissions and the
`www-data:www-data` owner, preserves existing application data, and does not perform
a recursive startup `chown`. A failure stops the entrypoint before the service can
become healthy.

Validate an image against empty bind mounts before deployment:

```bash
./scripts/validate-runtime-init.sh media-downloader-app:local
```

## Controlled deployment

Build from merged `main`, then recreate only the changed Save It services:

```bash
docker compose build extractor app nginx
docker compose up -d --no-deps extractor
docker compose up -d --no-deps app
docker compose up -d --no-deps worker
docker compose up -d --no-deps scheduler
docker compose up -d --no-deps nginx
```

Wait for health or running state after each command. Confirm that the Redis
container ID did not change.

Run the production validator from the same persistent project or rollback runtime
directory used by Compose:

```bash
./scripts/validate-production-runtime.sh \
  /opt/media-downloader \
  http://127.0.0.1:8099 \
  https://save.allmy.win
```

The validator requires five consecutive local and public landing-page successes,
both health endpoints, a writable Laravel log directory, matching app/Nginx
manifests, working CSS/JavaScript responses, and no `/tmp` bind mount for app,
worker, or scheduler. A healthy `/health` response never compensates for a failing
landing page.

## Persistent rollback runtime

Rollback checkouts and their bind-mounted `storage` and `database` directories must
remain under an explicit persistent path, for example:

```text
/opt/save-it-runtime-rollback-<release>-<timestamp>
```

Never start a long-lived container from a checkout below `/tmp`. Copy SQLite and
storage only after recording source checksums. Verify the copied SQLite checksum
before recreation, retain the existing Redis volume, initialize Laravel runtime
directories, and save the rollback commit and image inventory in the runtime
directory.

After rollback, run the same production validator and compare `.env` and SQLite
checksums with the pre-deploy record. Keep the failed-release images or source
bundle until the incident is closed.

## Byte-range acceptance

For a direct media download, validate at least:

- one byte request returns `206`;
- `Content-Type` is the expected media type;
- `Content-Length` is `1`;
- `Content-Range` is valid and describes the requested byte;
- `Accept-Ranges` is exactly `bytes`;
- a malformed or multi-range request fails safely.

Do not declare the deployment successful if partial delivery works but the public
response contract is incomplete.
