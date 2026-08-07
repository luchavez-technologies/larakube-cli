# Plan: Plex Commons Lifecycle Management (`plex:stop`, `plex:start`, Auto-Resume)

**Status:** ✅ BUILT — header said "Proposed". Verified 2026-08-08: `plex:start` and `plex:stop` both ship.

---

## 🎯 Objective

Introduce **selective lifecycle management for Plex Commons shared services** (`postgres`, `mysql`, `mariadb`, `redis`, `seaweedfs`, `meilisearch`).

When developers switch between projects (e.g. Project A using MySQL, Project B using Postgres), running multiple database engines in parallel consumes unnecessary RAM. This feature allows operators to pause (`replicas=0`) unused Plex Commons services, while ensuring `larakube up` and `larakube start` automatically resume required services on demand.

---

## 🏛 Command Signature Standard

Following LaraKube's universal command pattern where `{environment}` is always the primary positional argument:

### `larakube plex:stop`
```bash
larakube plex:stop {environment=local} {--service=}
```
- `larakube plex:stop local --service=mysql` -> Stops only `plex-mysql` in the target environment context.
- `larakube plex:stop local` -> Stops **all** Plex Commons services in the target environment context.

### `larakube plex:start`
```bash
larakube plex:start {environment=local} {--service=}
```
- `larakube plex:start local --service=mysql` -> Resumes only `plex-mysql`.
- `larakube plex:start local` -> Resumes **all** Plex Commons services.

---

## 📋 Implementation Checklist

- [ ] Create `cli/app/Commands/Plex/PlexStopCommand.php` (`plex:stop {environment=local} {--service=}`)
- [ ] Create `cli/app/Commands/Plex/PlexStartCommand.php` (`plex:start {environment=local} {--service=}`)
- [ ] Add `ensurePlexServiceRunning()` to `cli/app/Traits/InteractsWithPlex.php`
- [ ] Update `UpCommand` and `StartCommand` to auto-resume required Plex services
- [ ] Create Pest feature test suite (`cli/tests/Feature/PlexLifecycleTest.php`)
- [ ] Run Pest unit tests & static analysis (`./php vendor/bin/phpstan`)

---

## ✅ Definition of Done

- `larakube plex:stop local --service=mysql` scales `plex-mysql` to 0 replicas, freeing RAM while preserving PVC data.
- `larakube plex:stop local` (no `--service` flag) scales all Plex Commons services to 0 replicas.
- `larakube up` / `larakube start` auto-detects paused required Plex services and scales them back to 1 replica.
- Pest unit tests and PHPStan pass with 0 errors.
