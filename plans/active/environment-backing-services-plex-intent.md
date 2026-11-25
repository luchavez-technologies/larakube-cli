# Per-Environment Backing Services (Own Pod vs Plex Commons) — Blueprint Intent, Deploy Reconciliation & Desktop UI

## Context

When a project already uses Plex Commons in `local` (e.g. Postgres, Redis/Valkey, SeaweedFS), creating or configuring a new cloud environment (`staging`, `production`, etc.) via Desktop or `larakube env` / `cloud:configure` leaves `environments.<env>.plex` and `environments.<env>.managed` empty (`[]`). As a result, generated Kustomize overlays deploy standalone Postgres/Redis/SeaweedFS pods and PVCs inside the app's namespace (`{app}-{env}`), and users aren't asked whether they want per-namespace pods or to reuse the server's shared Plex Commons.

## Design Decisions

1. **Blueprint Model (Zero Schema Breakage):**
   Reuse the existing `environments.<env>.plex` and `environments.<env>.managed` arrays in `.larakube.json`:
   - `plex`: services the blueprint intends to back with **Plex Commons** in this environment.
   - `managed`: services actually provisioned/externalized on the cluster (populated by `plex:join` once allocated, or by external cloud providers like RDS).
   - `pendingPlex = array_values(array_diff($config->getPlex($env), $config->getManaged($env)))`: services chosen for Plex Commons that have not yet been joined on the cluster.
   - Because `ConfigData::getExternallyHosted($env)` returns `managed ∪ plex` and `isPlexBacked()` checks `plex`, storing intent in `plex` immediately generates Plex-backed K8s overlays (`*-managed-delete-*.yaml`, no service PVCs, no `wait-for-deps` on self-hosted pods) and omits self-hosted connection vars from generated CI workflows — while `PlexJoinCommand::detectExistingData()` (which checks `getManaged()`) still guards against stranding live self-hosted PVCs.

2. **When It Takes Effect:**
   - Saved to `.larakube.json` and manifests regenerated/pruned immediately (`cloud:configure <env> --only=plex --plex=...`, `cloud:configure <env> --only=target --plex=...`, or `env <name> --plex=...`).
   - Reconciled automatically on the cluster (via `plex:join <env> --context=... --service=... --no-interaction`) during **`cloud:deploy <env>`** and **`cloud:configure <env> --only=ci`**.
   - If `plex:join --no-interaction` leaves any service unjoined (e.g. existing self-hosted PVC data detected or Redis 16-DB capacity limit), reconciliation fails loudly with actionable `plex:migrate` / `plex:join --fresh` instructions.

3. **Granularity & Safety:**
   - Per eligible service (`postgres`, `mysql`, `mariadb`, `redis`, `seaweedfs`, `garage`, `meilisearch` per `PlexService::projectCommonsServices()`).
   - Switching a currently-joined service back to Own pod clears it from both `plex` and `managed` and regenerates/prunes manifests, leaving Commons data intact (safe Phase 1 leave semantics; user is informed about `plex:leave <env> --restore`). Unrelated external `managed` entries not in `previousPlex` are preserved.

4. **Desktop UI Surfaces:**
   - **Add Cloud Environment Drawer** (`AddEnvironmentDialog`): per-service picker with smart defaults based on the selected server's cached Plex Commons services (`PlexCatalog`).
   - **Environment Settings Drawer** (`EnvironmentSettingsDrawer`): dedicated **Backing Services** section (`'services'`) with Jump-To pill and Inertia `<Form>` submitting to `projects.backing-services`.
   - **Backing Services Card** (`BackingServicesCard` + `projects/show.tsx`): shows `"Plex Commons · Pending"` badge/note when `pending === true`, plus a `"Configure"` button opening Environment Settings to `'services'`.

## Verification Plan

- **CLI (`cli/`):**
  - `tests/Feature/EnvironmentPlexIntentTest.php` covering `--only=plex`, `--plex=` validation, `--only=target --plex=`, `env --plex=`, `cloud:deploy` reconciliation + loud failure guard, `cloud:configure --only=ci` reconciliation, and `BackingServices::describe()` `pending` flag.
  - Run `composer format`, `composer analyse`, and the **full** `composer test` suite.
- **Desktop (`desktop/`):**
  - Feature tests covering `projects.link` `--plex=` forwarding (and backwards compatibility when omitted), `projects.backing-services`, and `projects.show` exposing `scoutDriver` + cached `readyServers.*.plex`.
  - Run `composer lint`, `npm run check`, `npm run types:check`, `npm run build`, and the **full** `composer test` suite.
