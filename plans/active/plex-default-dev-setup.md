# Plan: Plex Commons Default Local Dev Setup & Auto-Provisioning

**Status:** Proposed Active Plan
**Created:** 2026-08-05
**Target Version:** LaraKube CLI v1.2.0

---

## 🎯 Objective

Establish **Plex Commons (`larakube-plex`)** as the default shared development setup across all LaraKube application scaffolding commands (`new`, `statamic:new`, `init`, `add`), reducing local RAM consumption by reusing shared PostgreSQL, MySQL, Redis, and S3 infrastructure.

---

## 🏛 Architecture & Execution Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as Developer
    participant NewCmd as LaraKube (new / statamic:new)
    participant PlexTrait as InteractsWithPlex Trait
    participant Cluster as K3s Cluster (larakube-plex)
    participant Docker as docker run (laravel/installer)

    User->>NewCmd: larakube new my-app (Select Postgres)
    NewCmd->>PlexTrait: 1. Check if Plex Postgres is running
    alt Plex Postgres not running
        PlexTrait->>Cluster: Auto-provision Plex Commons Postgres (with spinner)
    end
    NewCmd->>PlexTrait: 2. Pre-provision DB tenant 'my_app_local' & credentials
    NewCmd->>Docker: 3. docker run --add-host=host.docker.internal:host-gateway -e DB_HOST=host.docker.internal ... laravel new my-app --database=pgsql
    Docker-->>NewCmd: 4. Generates app with native pgsql .env & runs migrations
    NewCmd->>NewCmd: 5. Generates K8s manifests & OpenBao secrets
```

---

## 📋 Implementation Checklist

- [ ] Update `cli/app/Traits/GathersInfrastructureConfig.php` to default to Plex Commons engines and auto-trigger Plex bootstrap when missing.
- [ ] Add `ensurePlexProvisionedForApp()` helper to `cli/app/Traits/InteractsWithPlex.php`.
- [ ] Update `runLaravelNew()` in `cli/app/Commands/NewCommand.php` to inject DB env vars (`DB_HOST=host.docker.internal`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), `--add-host=host.docker.internal:host-gateway`, and `--database=pgsql`.
- [ ] Update `cli/app/Commands/Statamic/StatamicNewCommand.php`.
- [ ] Create Pest feature test suite (`cli/tests/Feature/PlexAutoProvisioningTest.php`).
- [ ] Run Pest unit tests & static analysis (`./php vendor/bin/phpstan`).

---

## ✅ Definition of Done

- `larakube new` and `statamic:new` auto-provision missing Plex Commons services with an informational spinner.
- Database tenants are pre-provisioned in Plex Postgres/MySQL before `laravel new` runs.
- `laravel new` receives `--database=pgsql` (or `mysql`) and DB env vars, writing a clean `.env` and executing initial migrations on step 1.
- All Pest tests and PHPStan pass with 0 errors.
