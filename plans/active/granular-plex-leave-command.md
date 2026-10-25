# Plan: Granular Service Disconnection in `plex:leave`

## 1. Motivation & Context
In LaraKube, projects can share backing infrastructure (Postgres, Redis, SeaweedFS S3) via the shared Plex Commons (`.larakube.json` stores `plex: string[]` per environment).
Currently, running `larakube plex:leave` disconnects the entire environment at once, stripping all services from Commons and forcing all of them to become self-hosted.

An app often needs to self-host only its primary database (e.g., dedicated performance or custom extensions) while keeping Redis in Commons for lightweight caching/queues.

## 2. CLI Enhancements
1. **`PlexLeaveCommand` (`cli/app/Commands/Plex/PlexLeaveCommand.php`)**:
   - Add `{--service=* : Specific service(s) to disconnect (e.g. postgres, redis, seaweedfs). Omit to disconnect all.}`
   - Support service aliases: `db`/`database` -> mapped DB engine; `cache` -> `redis`; `storage`/`s3` -> mapped S3 engine.
   - Interactive prompt when multiple services exist in Commons and `--service` is omitted, offering to disconnect all or specific services.
   - Update `clearPlexMarkers()` to accept `$targetServices`: only removes disconnected services from `plex` and `managed`, preserving remaining Commons services.
   - Update `finishLeaving()`:
     - Only drops/flushes resources for the disconnected services.
     - Updates tenant registry: unsets disconnected services, and only deletes the tenant entry from the registry if no Commons services remain.

## 3. Testing & Verification
- Unit/Feature tests in `cli/tests/Feature/PlexLeaveGranularTest.php`:
  - Disconnecting a single service (e.g. `postgres`) retains remaining services (`redis`) in `.larakube.json`.
  - Disconnecting all services clears the tenant from the registry.
- Run `composer format`, `composer analyse`, `composer test`.
