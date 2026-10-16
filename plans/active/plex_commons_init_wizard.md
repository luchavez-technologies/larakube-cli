# Plex Commons Initialization Wizard Plan

**Status:** Completed  
**Author:** Antigravity  
**Date:** 2026-10-15  
Provide an interactive Setup Wizard in LaraKube Desktop (`PlexEmptyState`) allowing operators to choose their Database Engine (MySQL, PostgreSQL, MariaDB), Cache & Queues (Redis), S3 Object Storage (SeaweedFS, MinIO, Garage), and Companion Services (Meilisearch, Headless Chrome) before initializing Plex Commons on a cluster.

## Background & Problem
Previously, the `PlexEmptyState` page contained a single button: "Initialize Plex Commons". Clicking it sent a blind POST request to `servers/{server}/plex/init`, running `plex:init --context=...` non-interactively without `--services=`.
Because background commands run non-interactively, the CLI could not prompt the operator and silently defaulted to `['postgres', 'redis']`. Users expecting MySQL or wishing to customize their shared stack were confused and left with PostgreSQL by default.

## Solution Architecture

### 1. Backend Controller (`desktop/app/Http/Controllers/PlexController.php`)
- Update `initServer(Request $request, string $server, StackCatalog $stacks, CliRunner $runner)`
- Validate optional `services` array:
  ```php
  $request->validate([
      'services' => ['nullable', 'array'],
      'services.*' => ['string'],
  ]);
  ```
- If provided and non-empty, pass `--services=` with comma-delimited service keys to `plex:init`:
  `$args[] = '--services=' . implode(',', $services);`
- Retain fallback behavior if no services are provided for backward compatibility.

### 2. Frontend Setup Wizard (`desktop/resources/js/pages/plex/plex-empty-state.tsx`)
- Render an interactive, intuitive **Plex Commons Setup Wizard**:
  - **Database Engine Selector** (Radio Cards):
    - **MySQL** (Port 3306) — Industry-standard relational DB for WordPress & Laravel apps.
    - **PostgreSQL** (Port 5432) — Robust relational DB with advanced JSON/indexing.
    - **MariaDB** (Port 3306) — High-performance open-source relational DB.
  - **Cache & Queues** (Toggle / Checkbox):
    - **Redis** (Port 6379, checked by default) — In-memory store sliced per-tenant.
  - **S3 Object Storage** (Radio Cards / Options):
    - **SeaweedFS** (Recommended, lightweight S3)
    - **MinIO** (Full S3 API)
    - **None / Skip** (Do not deploy S3 on Commons)
  - **Companion Services** (Checkboxes):
    - **Meilisearch** (Port 7700) — Lightning-fast search engine
    - **Headless Chrome** (Port 9222) — Headless Chromium for PDF rendering & Puppeteer
- Real-time preview summary showing:
  - Selected engines
  - Memory & footprint profile estimate
  - Exact command that will run
- Ensure all action buttons follow the UI Icon Standard (e.g. `<Layers className="h-4 w-4" /> Initialize Plex Commons`).

### 3. Automated Tests (`desktop/tests/Feature/PlexTest.php`)
- Add test coverage for `initServer` passing custom `--services`:
  - `initServer with custom services (e.g. mysql, redis, seaweedfs)`
  - Verify `--services=mysql,redis,seaweedfs` is passed to the spawned process.
- Run tests (`composer test`), formatters (`composer format`), and linter (`composer analyse`).

## Verification
- Run desktop pest tests.
- Verify typescript compilation and react components.
