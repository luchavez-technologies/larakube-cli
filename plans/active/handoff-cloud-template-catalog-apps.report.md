# Report: Cloud Template Catalog Apps (Batch 1 & Batch 2)

## Summary

- **21 apps processed** across Batch 1 (10) and Batch 2 (11):
  - **CLI vendors (`HasImages` + `PinsImages`)**: Added `images()` to 19 vendors; `Supabase` and `Windmill` already implemented `HasImages`.
  - **`tests/Feature/AppImagePinsTest.php`**: Added 18 apps to the dataset (3 skipped because their templates read images from PHP variables rather than literal strings; listed below).
  - **`config/templates.php` (`downloadable`)**: Added all 21 app slugs.
  - **`app/Commands/Cloud/CloudAppsInstallCommand.php` (`APPS`)**: Added all 21 app slugs.
  - **Cloud (`../cloud`)**: Added all 21 app entries to `database/seeders/CatalogAppSeeder.php`, removed the migration-seeding anti-pattern (`2026_11_25_060031_seed_official_catalog_apps.php` deleted), made `DatabaseSeeder` strictly idempotent (dropped Laravel's boilerplate `User::factory()->create()`), and enabled `AUTORUN_LARAVEL_MIGRATION_SEED="true"` in `Dockerfile.php` and `.infrastructure/k8s/base/laravel.yaml`.
- **Template edits**: Only `resources/views/k8s/kuma/shared.blade.php` received container `resources.requests`/`limits` (`50m`/`128Mi` -> `250m`/`256Mi`) required by `ToolResourceRequestsTest` once the CLI unshipped gate was removed. No other templates were edited.
- **Zero image tag, version, or registry edits.**

---

## Per-App Status & Declared Images

### Batch 1

| App | Status | Vendor | `AppImagePinsTest` | Cloud Prefix / Category | Declared Images (`key => image`) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `kuma` | Done | `App\Vendors\UptimeTool` | Added | `status` / `monitoring` | `kuma => louislam/uptime-kuma:1` |
| `vaultwarden` | Done | `App\Vendors\PasswordTool` | Added | `vault` / `security` | `vaultwarden => vaultwarden/server:1.37.5` |
| `outline` | Done | `App\Vendors\NoteTool` | Added | `notes` / `productivity` | `outline => outlinewiki/outline:1.9.2` |
| `umami` | Done | `App\Vendors\AnalyticsTool` | Added | `analytics` / `analytics` | `umami => ghcr.io/umami-software/umami:postgresql-latest` |
| `yopass` | Done | `App\Vendors\YopassTool` | Added | `paste` / `security` | `yopass => jhaals/yopass:14.8.0` |
| `planka` | Done | `App\Enums\TaskTool::PLANKA` | Added | `tasks` / `productivity` | `planka => ghcr.io/plankanban/planka:2.2.1` |
| `kutt` | Done | `App\Vendors\LinkTool` | Added | `link` / `productivity` | `kutt => kutt/kutt:v3.2.6` |
| `teable` | Done | `App\Vendors\SheetTool` | Added | `sheet` / `productivity` | `teable => teableio/teable:release.2026-07-26T01-04-56Z.2377` |
| `documenso` | Done | `App\Vendors\SignTool` | Added | `sign` / `business` | `documenso => documenso/documenso:v2.16.0` |
| `resume` | Done | `App\Vendors\ResumeTool` | Added | `resume` / `productivity` | `resume => amruthpillai/reactive-resume:v5.2.5` |

### Batch 2

| App | Status | Vendor | `AppImagePinsTest` | Cloud Prefix / Category | Declared Images (`key => image`) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `supabase` | Done (vendor already had `HasImages`) | `App\Tools\Supabase` | **Skipped** (PHP `image()` calls) | `supabase` / `backend` | `studio => supabase/studio:2026.11.02-sha-a05e7ec`<br>`gateway => envoyproxy/envoy:v1.39.6`<br>`auth => supabase/gotrue:v2.197.0`<br>`rest => postgrest/postgrest:v14.18`<br>`realtime => supabase/realtime:v2.148.3`<br>`storage => supabase/storage-api:v1.89.12`<br>`imgproxy => darthsim/imgproxy:v4.0.21`<br>`meta => supabase/postgres-meta:v0.100.0`<br>`functions => supabase/edge-runtime:v1.77.9`<br>`db => supabase/postgres:17.6.1.136`<br>`supavisor => supabase/supavisor:2.9.13` |
| `directus` | Done | `App\Enums\DataTool::DIRECTUS` | Added | `data` / `backend` | `directus => directus/directus:12` |
| `windmill` | Done (vendor already had `HasImages`) | `App\Tools\Windmill` | **Skipped** (PHP `image()` calls) | `windmill` / `automation` | `windmill => ghcr.io/windmill-labs/windmill:1.770.0`<br>`lsp => ghcr.io/windmill-labs/windmill-lsp:1.134.1`<br>`postgres => postgres:15-alpine` |
| `twenty` | Done | `App\Vendors\CrmTool` | Added | `crm` / `business` | `twenty => twentycrm/twenty:v2.35.0` |
| `grafana` | Done | `App\Vendors\MonitorTool` | Added | `grafana` / `monitoring` | `grafana => grafana/grafana:10.4.2`<br>`prometheus => prom/prometheus:v2.51.2`<br>`loki => grafana/loki:3.0.0`<br>`promtail => grafana/promtail:3.0.0`<br>`tempo => grafana/tempo:2.10.7`<br>`kube-state-metrics => registry.k8s.io/kube-state-metrics/kube-state-metrics:v2.12.0` |
| `forgejo` | Done (see registry & template notes) | `App\Enums\GitForgeTool::FORGEJO` | **Skipped** (PHP version vars) | `git` / `developer` | `forgejo => codeberg.org/forgejo/forgejo:16.0.4`<br>`runner => code.forgejo.org/forgejo/runner:13.1.0`<br>`podman => quay.io/podman/stable:v5.8.4`<br>`job => docker.io/library/node:24-trixie` |
| `metabase` | Done | `App\Vendors\InsightTool` | Added | `insights` / `analytics` | `metabase => metabase/metabase:v0.63.1` |
| `glitchtip` | Done | `App\Vendors\ErrorTool` | Added | `errors` / `monitoring` | `glitchtip => glitchtip/glitchtip:6.2.2`<br>`db => postgres:15-alpine`<br>`cache => valkey/valkey:8.0-alpine` |
| `penpot` | Done | `App\Enums\DesignTool::PENPOT` | Added | `design` / `design` | `backend => penpotapp/backend:2.17`<br>`frontend => penpotapp/frontend:2.17`<br>`exporter => penpotapp/exporter:2.17` |
| `chatwoot` | Done | `App\Vendors\SupportTool` | Added | `support` / `business` | `chatwoot => chatwoot/chatwoot:v4.16.2` |
| `sendrec` | Done | `App\Vendors\RecordTool` | Added | `record` / `productivity` | `sendrec => ghcr.io/sendrec/sendrec:v1.89.1` |

### Skipped Apps (per Handoff Rules)

- **Skipped completely (cross-app dependencies, reserved for Claude)**: `zitadel`, `stalwart`, `bulwark` (requires `stalwart`), `netbird`, `matrix`, `headlamp`, `livekit`, `ocis`. (`plausible` was dropped completely per follow-up instruction.)
- **Skipped in `AppImagePinsTest.php` dataset only (images rendered from PHP variables rather than literal strings in `resources/views/k8s/<folder>/*.blade.php`)**:
  1. `supabase`: Templates call `{{ $tool->vendor()->image('...') }}` (`Supabase` already implemented `HasImages`).
  2. `windmill`: Template calls `{{ $tool->image('...') }}` (`Windmill` already implemented `HasImages`).
  3. `forgejo`: `resources/views/k8s/forgejo/forgejo.blade.php` builds image strings from `$forgejoVersion`, `$runnerVersion`, `$podmanVersion`, and `$jobImage` passed by `ForgejoInitCommand` constants (`FORGEJO_VERSION`, `RUNNER_VERSION`, `PODMAN_VERSION`, `JOB_IMAGE`).

---

## Suspect, Stale, Floating, or Unusual-Registry Images (Untouched)

1. **`forgejo` — registries blocked by `ImageDigestResolver` (will block `templates:publish forgejo`)**:
   - `codeberg.org/forgejo/forgejo:16.0.4` and `code.forgejo.org/forgejo/runner:13.1.0`: neither `codeberg.org` nor `code.forgejo.org` is in `ImageDigestResolver::ALLOWED_REGISTRIES` (`cli/app/Support/Templates/ImageDigestResolver.php`) or `ImageDigestResolver::REGISTRIES` (`cloud/app/Services/Catalog/ImageDigestResolver.php`). Publishing `forgejo` will throw a disallowed registry exception until those hosts are allowlisted or mirrored.
   - `docker.io/library/node:24-trixie`: rolling major+distro tag (`24-trixie`, no patch version).
2. **`umami` — floating tag (`:postgresql-latest`)**:
   - `ghcr.io/umami-software/umami:postgresql-latest` is an unpinned floating tag rather than an immutable version tag.
3. **`kuma` — floating major tag (`:1`)**:
   - `louislam/uptime-kuma:1` pins only major version `1` (and trails Uptime Kuma 2.x).
4. **`directus` — floating major tag (`:12`)**:
   - `directus/directus:12` pins only major version `12` without minor/patch.
5. **`penpot` — floating minor tags (`:2.17`)**:
   - `penpotapp/backend:2.17`, `penpotapp/frontend:2.17`, `penpotapp/exporter:2.17` omit patch versions.
6. **`teable` — old timestamped build tag**:
   - `teableio/teable:release.2026-07-26T01-04-56Z.2377` uses a non-semver timestamped build tag from July 2026.
7. **`grafana` — stale component versions + local exporter sub-templates**:
   - `grafana/grafana:10.4.2`, `prom/prometheus:v2.51.2`, `grafana/loki:3.0.0`, `grafana/promtail:3.0.0`, and `registry.k8s.io/kube-state-metrics/kube-state-metrics:v2.12.0` are old 2024-era pins alongside a much newer `grafana/tempo:2.10.7`.
   - `resources/views/k8s/grafana/exporters/{database,cache}.blade.php` use `{{ $driver->exporterImage() }}` (invoked by `DeploysMonitoringExporters` during local `larakube up`, not `GrafanaInitCommand`, but included in the `grafana/` template bundle).
8. **`windmill` & `glitchtip` — bundled `--no-plex` storage images**:
   - `postgres:15-alpine` (in both `windmill` and `glitchtip`): rolling major-only tag on Postgres 15 (whereas Commons uses Postgres 17).
   - `valkey/valkey:8.0-alpine` (in `glitchtip`): minor-only rolling tag.
9. **`supabase` — mixed tag formats & tags to verify upstream**:
   - `supabase/studio:2026.11.02-sha-a05e7ec` (date+sha tag) and `postgrest/postgrest:v14.18` (minor-only tag).
10. **Upstream tag verification during `templates:publish`**:
    - Several semver tags (`vaultwarden/server:1.37.5`, `metabase/metabase:v0.63.1`, `twentycrm/twenty:v2.35.0`, `documenso/documenso:v2.16.0`, `amruthpillai/reactive-resume:v5.2.5`, `chatwoot/chatwoot:v4.16.2`, `ghcr.io/sendrec/sendrec:v1.89.1`, `outlinewiki/outline:1.9.2`, and `Supabase` component versions) look potentially ahead of or unverified against upstream Docker Hub / GHCR releases; `templates:publish` will verify each tag's manifest existence against the live registry.

---

## Additional Behavioral Notes for Review

- **Unshipped gate & `plausible` stub removed**: Removed `RefusesUnshippedTools`, `ClusterTool::isShipped()`, `ClusterTool::shippedCases()`, and the unbuilt `ClusterTool::PLAUSIBLE` stub across the CLI so every tool in `ClusterTool` is treated as shipped (gating moves to LaraKube Cloud). Added `kuma` and `umami` footprint mappings in `ToolFootprint` and resource requests in `resources/views/k8s/kuma/shared.blade.php`.
- **`documenso` Commons requirement**: `DocumensoInitCommand` calls `ensureCommons(['postgres', 'seaweedfs', 'headless-chrome'])`. `TemplateApp::plexImages()` already pins `headless-shell`, though `CloudAppsInstallCommand::PLEX_SERVICES` only lists `postgres, redis, mariadb, seaweedfs` for standalone `plex` installs.
- **Pre-existing `erpnext` prefix mismatch**: `CatalogAppSeeder` seeds `'erpnext'` with `'prefix' => 'erp'`, whereas `SharedClusterService::ERPNEXT->hostPrefix()` is `'erpnext'` (`ErpnextInitCommand` uses `resolveToolHost()`, which turns `--domain=erp.example.com` into `erpnext.erp.example.com` unless the domain starts with `erpnext.`). Every prefix added in Batch 1 & 2 matches its `SharedClusterService::hostPrefix()`.

---

## Check Output

Commands run:

```bash
# CLI Format, Static Analysis, and Full Test Suite
composer format
# -> Rector + Pint passed (0 errors)
composer analyse
# -> PHPStan passed (0 errors)
./vendor/bin/pest --parallel
# -> 4090 passed, 6 skipped, 0 failures (LoopbackHttpServerTest verified outside sandbox: 4 passed)

# Cloud Pint and Full Test Suite
./vendor/bin/pint database/seeders/CatalogAppSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/AppReleasePipelineTest.php
# -> passed
./vendor/bin/pest
# -> 283 passed (1587 assertions), 0 failures
```
