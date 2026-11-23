# 0035: The Commons Postgres Ships pgvector; Laravel AI Tenants Get the Extension at Allocation

**Status:** Accepted

## Context

The Laravel AI SDK's retrieval features need the `vector` type from pgvector. A project with its own Postgres already ran `pgvector/pgvector:pg17` when it had the AI feature, but a project joining the shared Plex Commons got plain `postgres:17.9`, with no way to use vectors.

Facts checked on 2026-11-22:

- `pgvector/pgvector` is built `FROM postgres:$PG_MAJOR-$DEBIAN_CODENAME` and only adds the extension's files ([Dockerfile](https://github.com/pgvector/pgvector/blob/master/Dockerfile)). Nothing changes in a database until `CREATE EXTENSION vector` runs there.
- The plain `postgres:17.x` tags are Debian trixie ([official-images](https://github.com/docker-library/official-images/blob/master/library/postgres)), and `pgvector/pgvector:0.8.7-pg17-trixie` is built on the same release. Same major version and same C library, so a data folder moves between the two images with no dump and no reindex. Moving between C libraries (Alpine's musl, or an older glibc on bookworm) can change text collation and leave indexes needing a rebuild.
- pgvector's `vector.control` has no `trusted = true`, so only a superuser can run `CREATE EXTENSION vector`. A Commons tenant owns its database but is not a superuser.
- An extension is per database: it has to be created in each tenant's database.

## Decision

1. **The Commons Postgres always runs the pgvector image**, pinned as `DatabaseDriver::PGVECTOR_IMAGE` (`pgvector/pgvector:0.8.7-pg17-trixie`). Tenants that never create the extension see plain Postgres.
2. **An existing Commons moves on its next `plex:init`.** `PlexService::normalizeCommonsSpec()` replaces a saved image that is plain Postgres 17 on trixie (`postgres:17`, `postgres:17.N`, `…-trixie`, with or without `docker.io/library/`) with the pgvector image. This is an ongoing rule, not one-time cleanup: it applies whenever such an image is saved. Anything else is kept as it is: Alpine, bookworm, another major version, a custom registry image, or a pgvector image already chosen.
3. **The Commons admin turns the extension on for Laravel AI tenants.** `DatabaseDriver::commonsTenantSql(..., withVector: true)` appends `CREATE EXTENSION IF NOT EXISTS vector;` after connecting to the tenant's database. `plex:join`, `plex:provision` and `plex:migrate` pass it when the project has the AI feature. Re-running is idempotent. MySQL and MariaDB ignore it.
4. **Both Postgres images are pinned constants** on `DatabaseDriver`: `POSTGRES_IMAGE` (`postgres:17.12`) and `PGVECTOR_IMAGE`. The per-project Postgres uses the pgvector one with the AI feature, as before, now pinned instead of the floating `pg17` tag. Both stay on the same major version and Debian release; bump them together.

## Consequences

- Moving an existing Commons restarts its Postgres once, a few seconds of downtime for every tenant.
- A Laravel AI project that joined before this change has no extension until `plex:join` runs again for it.
- `plex:rotate` re-runs tenant allocation without the vector step. It never removes an extension, so a rotated tenant keeps one it has.
- In the project form, Laravel AI still only *suggests* Postgres. It is not locked: AI works without vector search on other databases.
- When pgvector or Postgres moves to a new major version, the data folder no longer carries over between images. That needs the backup and restore path (`backup:run`, then `backup:restore`), and the image rule in decision 2 must be updated to match the new major.
