# Upgrade, rollback, and releases

## Upgrade

Read the release notes and migration notes before changing a production instance.
Operators are responsible for taking and verifying their own database and persistent
storage backup before upgrades.

```bash
git fetch --tags origin
git checkout <release-tag-or-commit>
docker compose build app nginx extractor
docker compose run --rm --no-deps app php artisan migrate --force
docker compose up -d --no-deps app worker scheduler nginx
```

Recreate `extractor` only when its source/image changed. Never recreate Redis for a
routine application upgrade. Verify `docker compose ps`, `/health`, `/ready`, and
the public landing page after recreation. Migrations are additive where documented;
do not assume every future migration is reversible or compatible with an older app.

## Rollback

Choose a previously tested application tag or commit, rebuild its images, and
recreate only its affected services. Preserve Redis and the existing persistent
database/storage mounts unless an incident plan explicitly says otherwise.

Before rolling back across any migration boundary, read the migration notes and
confirm database compatibility. A code/image rollback does not guarantee a safe
schema rollback, especially after destructive or data-transforming migrations.
Restore data only from an operator-verified backup and repeat health/readiness and
functional checks afterwards.

## Release policy

Save It uses Semantic Versioning (`MAJOR.MINOR.PATCH`) for tagged releases. Release
notes should list user-facing changes, security notes, migration instructions,
upgrade/rollback caveats, and any breaking changes. Tags are created only after CI
passes; a release tag is not a substitute for the operator's own deployment and
backup procedure.
