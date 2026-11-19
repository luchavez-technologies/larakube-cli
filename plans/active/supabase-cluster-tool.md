# Supabase cluster tool + Desktop flip card

## Status: BUILT. Not yet installed on a live server.

- **Built:** Part B (CLI `1b4d1625`, review fixes `af2a3ede`, upstream-parity fixes in the commit adding this status) and Part C (Desktop `7a02569`, sizes and fit `2634507`, focus handling in the commit after it).
- **Checked against upstream `docker/` (master):**
  - Image tags, vendored SQL and Envoy config are identical to upstream.
  - Every service's environment was diffed against `docker-compose.yml`.
  - Envoy loads the rendered gateway config: 7 clusters plus the listener. Studio answers 401 without basic auth, and the REST API answers 401 without an apikey.
  - The database, Auth, PostgREST, Storage, Realtime, Supavisor and Edge Functions all ran locally in Docker with the exact rendered settings. The role logins work, pgvector is enabled, and the pooler tenant is created.
- **Remaining:**
  1. A live install, on GCP first: Studio login, sign-up through `/auth/v1/signup`, an upload landing in the Commons bucket, and the vector extension present.
  2. Including Supabase's database in `backup:run` (ADR 0032 follow-up).
  3. The asymmetric `sb_` keys: the stack runs in upstream's legacy HS256 mode (`ANON_KEY_ASYMMETRIC` empty), as upstream's default `.env` does.
  4. The S3 protocol endpoint (`S3_PROTOCOL_ACCESS_KEY_*`): upstream offers it, but it isn't wired here.
  5. Deploying Edge Functions: they live on the functions volume, one folder each, and nothing deploys to it yet.


## Context

In a demo, the user's friend wanted **numbers**, and was shocked that a fresh server already showed about half its memory in use before any apps were installed. Separately, PocketBase drew less interest than expected on the dashboard, and Supabase should take its place. On a small server, though, Supabase (about 4 GB) can't fit at all.

Findings from reading the code:

1. **The "half used" is real usage, not requests.** The Desktop server page reads `kubectl top nodes` (`desktop/app/Services/LaraKube/ClusterMetrics.php:20`). Traefik (`cli/resources/views/k8s/traefik-*.blade.php`) and ExternalDNS (`k8s/external-dns/*`) declare **no** `resources:` at all. On a 2 GB node most of the usage is the k3s control plane plus the OS, which run outside pods.
2. **The capacity guard is optimistic.** `ClusterCapacitySnapshot` (`cli/app/Data/ClusterCapacitySnapshot.php`) computes free memory as allocatable minus *pod requests*, so unrequested pods and non-pod k3s memory count as free.
3. **Desktop skips the guard.** `QuickActionController::launch()` (`desktop/app/Http/Controllers/QuickActionController.php:~101`) and `Mcp/Tools/InstallClusterToolTool.php:171` always pass `--force`, which is also the guard's bypass (`AbstractToolInitCommand::guardClusterCapacity()`, :68).
4. **There is no footprint catalog.** The capacity-guard plan (`cli/plans/active/pre-install-cluster-capacity-guard.md`, Phases 0–2 shipped) settled on deriving numbers from the rendered manifest rather than hand-writing a second figure. Only Forgejo, the monitoring stack and n8n have real `resources:` blocks, and `tool:catalog --json` exposes no sizes.

**Goal:** tell users plainly what each app needs, what their server has, and when an app won't fit, offer three ways out: **pause unused apps, upsize the server, or pick a lighter alternative**. Desktop must refuse an install that won't fit, such as Supabase on a 2 GB droplet.

**Build order:**
- **Part A, capacity and fit.** Done first, because the other parts depend on it. Saved as `cli/plans/active/app-fit-and-capacity.md`.
- **Part B, Supabase tool.** Saved as `cli/plans/active/supabase-cluster-tool.md`.
- **Part C, Desktop flip card.** Saved in the same Supabase plan file.

---

## Part B: Supabase cluster tool (CLI)

### Research (live, 2026-11-15)
- **Official route:** Docker Compose only. Kubernetes options are community-made (`supabase-community/supabase-kubernetes` v0.8.4). There is **no official all-in-one image**; third-party builds such as `train360-corp/supabase-container` are ruled out by the no-unofficial-integrations rule. We write our own Blade manifests using the official images. License is Apache-2.0.
- **Images**, from upstream `docker/docker-compose.yml`; re-verify each tag before pinning:

  | Service | Image |
  |---|---|
  | studio | `supabase/studio:2026.11.02-sha-a05e7ec` |
  | gateway | `envoyproxy/envoy:v1.39.6` (upstream's default now; Kong is an override) |
  | auth | `supabase/gotrue:v2.197.0` |
  | rest | `postgrest/postgrest:v14.18` |
  | realtime | `supabase/realtime:v2.148.3` |
  | storage | `supabase/storage-api:v1.89.12` |
  | imgproxy | `darthsim/imgproxy:v4.0.21` |
  | meta | `supabase/postgres-meta:v0.100.0` |
  | functions | `supabase/edge-runtime:v1.77.9` |
  | db | `supabase/postgres:17.6.1.136` |
  | supavisor | `supabase/supavisor:2.9.13` |

  Logflare and Vector are an optional overlay and are left out of scope.
- **Sizing:** upstream says minimum 2 CPU / 4 GB RAM, recommended 4 CPU / 8 GB. That is exactly why Part A comes first.

### Decisions
1. **Full upstream stack by default; heavier parts are optional, as in Grafana's stack.**
   - Flag pairs, all defaulting **on**: `--no-realtime`/`--with-realtime`, `--no-functions`/`--with-functions`, `--no-imgproxy`/`--with-imgproxy`, `--no-pooler`/`--with-pooler`. Each description states the RAM it saves, taken from the footprint.
   - Interactive runs get a `multiselect` with everything selected; non-interactive runs install the full stack. This mirrors `GrafanaInitCommand::resolveMonitoringComponents()` and its in-place reconcile.
2. **One dedicated `supabase/postgres` per instance.** It never uses Commons and is never shared between instances:
   - Roles such as `anon` and `authenticator` exist cluster-wide.
   - The pgsodium/Vault root key covers the whole server.
   - The setup scripts need superuser.

   Supabase is the first tool that always needs its own Postgres.
3. **Commons supplies only S3 (one bucket per instance) and the SMTP relay.** Supabase needs no Commons Postgres and no Valkey/Redis; upstream has no Redis service.
4. **pgvector 0.8.2 is enabled at init** with `create extension if not exists vector with schema extensions;`, which is idempotent. pg_trgm, pgroonga, rum and hypopg are shipped in the image but left off.

### Implementation
- **Enum and vendor:**
  - Add `ClusterTool::SUPABASE` with the usual arms (`vendor`, `getLabel`, `icon`, `brandName`, `service`, `tagline`, `stack()`, `categories` DATABASE/BACKEND/AUTH/STORAGE, `commonsCapabilities` s3+smtp, `requiresAdminEmail`, `hasInstanceAwareRemoval`, `resourceNaming` CANONICAL) and `alternatives()` → [POCKETBASE].
  - Add the vendor `app/Tools/Supabase.php` (`PinsImages`; `HasImages`, `HasWorkloadComponents`, `HasCommonsBuckets`, `HasSmtpWiring`, plus A2's `footprintManifests()`).
- **Registration:** options in `ToolInitSpec.php`, following the Grafana arm at :107; register in `ToolInitCommands.php` (:61/:129).
- **`SupabaseInitCommand`** extends `AbstractToolInitCommand`, modelled on `PocketBaseInitCommand::deployPocketBase()`:
  - **Idempotent secrets** (read the existing Secret or OpenBao value before generating anything):
    - A new `app/Support/SupabaseKeys.php` generates the HS256 anon and service_role JWTs, the asymmetric key pair and the publishable/secret keys, matching upstream's `utils/*.sh`.
    - Values are pushed with `putSecret` and `pushClusterSecret`/`syncClusterSecretToNamespace`.
  - **Bucket:** `allocateStorageBucket()`, or a PVC when `--no-plex`.
  - **Apply:** `applyAndVerifyRollout(tool:)`, which runs the guard.
  - **Vector:** once the db is Ready, run the `create extension` statement through `kubectl exec`.
  - **Register:** `registerDeployedTool`.
  - **Naming:** everything is per instance (`supabase-db-app-example-com`, …).
- **Manifests** in `resources/views/k8s/supabase/`, all with real requests:
  - `db.blade.php`: StatefulSet, RWO PVC and Service. Init SQL comes from a ConfigMap vendored from upstream `docker/volumes/db/*.sql`; a separate PVC holds the pgsodium config.
  - `core.blade.php`: auth, rest, meta, storage and studio.
  - `optional.blade.php`: realtime, functions, imgproxy and supavisor, each under `@if`.
  - `gateway.blade.php`: Envoy plus a ConfigMap vendored from upstream `volumes/api/envoy/*`. Studio sits behind basic auth.
  - `ingress.blade.php`: a single host with the Traefik and VPN middleware.
- **Remove, show and logs:**
  - `SupabaseRemoveCommand` follows `N8nRemoveCommand::teardown()`. By default it keeps the db PVC and keys; `--purge` also removes them and the bucket.
  - Show and logs use the shared abstract classes.
- **Tests** follow ADR 0019:
  - `Unit/SupabaseManifestYamlTest.php`: every component combination renders, parses and has requests.
  - `Feature/SupabaseInitCommandTest.php`:
    - default full stack, and the `--no-*` flags;
    - re-running reuses existing secrets;
    - OpenBao push;
    - the `--no-plex` PVC path;
    - the vector extension is created;
    - two `--domain` instances get separate resources;
    - no Commons Postgres or Redis tenant is leased;
    - the capacity refusal at `never_fits`.
  - `Unit/SupabaseKeysTest.php`, a remove test in `ToolRemoveCommandTest.php`, and an update to the `tool-init-signatures.json` fixture.
- **ADR:** "Supabase runs a dedicated per-instance `supabase/postgres`". It also records the follow-up that `backup:run` doesn't cover this database yet.

## Part C: Desktop Supabase/PocketBase flip card
- **Card:** in `resources/js/components/welcome-onboarding.tsx` (`FEATURED_APPS` :33), the PocketBase slot becomes a flip card.
  - **Front:** Supabase, with chips, **"Needs ~X GB RAM" from `footprint`**, the fit badge for the user's servers (from A5's cached capacity) and a "Running on a small server? →" link.
  - **Back:** PocketBase as the lighter alternative, with its footprint and its own Quick Launch button.
  - **Flipping:** only when clicked (user's choice). When no server fits Supabase, the front shows an amber line: "Your servers are short on memory, see the lighter option".
- **Animation:** `motion` v14 is already a dependency, used in `pages/onboarding/index.tsx`.
  - The card rotates around the Y axis (`preserve-3d`, `backface-visibility: hidden`) with a ~450 ms spring. When `useReducedMotion()` is on, it crossfades instead.
  - The flip controls are real buttons; the hidden side gets `inert` and `aria-hidden`; focus moves to the side now showing.
- **`quick-launch-modal.tsx`:** add `'supabase'` to `QuickLaunchAppId` (:59) and keep pocketbase. The Supabase `APPS` entry uses `adminPath: '/'`. `initialApp` follows the side that launched the modal, and the A5 fit panel applies here too.
- **Logo:** add `SiSupabase` to `components/tool-logo.tsx` and the short-name map in `pages/tools/index.tsx:1699`.

## Out of scope / follow-ups
- Supabase's Logflare analytics.
- Including Supabase's database in `backup:run`.
- Docs pages.
- SSO for Studio: self-hosted Studio has no OIDC, so it relies on basic auth plus `--vpn-only`.

## Verification
- **Supabase on OrbStack:**
  - pods are Ready, and Studio asks for basic auth;
  - `/rest/v1/` with the anon key returns 200, and signup through `/auth/v1/signup` works;
  - an upload lands in the Commons bucket, and the `vector` extension is present;
  - a re-run leaves the secrets and data unchanged;
  - `--no-functions` removes Edge Functions in place.
- **Desktop:**
  - `php artisan test` passes;
  - the server page shows the System / Platform / Apps / Free bar;
  - Quick Launch of Supabase to the 2 GB droplet is disabled, showing the pause, upsize and alternative actions; pausing an app re-runs the fit check;
  - the flip card works both ways, and reduce motion gives a crossfade.
