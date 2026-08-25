# Component Plan: `drive:office:*` — Euro-Office document editing for Drive

**Status:** 🟡 SPIKE-GATED — design approved 2026-08-24, but five unresolved facts must be verified live against the cluster before final command/manifest code is written (see below). Not started.
**Created:** 2026-08-24
**Related:** `drive-mcp-server.md` (separate plan, same tool, deliberately not merged with this one)

---

## Why this exists

Drive (`drive:init`) deploys oCIS — file storage/sync only, no document *creation* capability, unlike Google Drive/MS 365. This plan adds real document editing wired to the existing Drive instance.

Two candidate WOPI editors were investigated: Collabora Online (mature, oCIS ships a first-party official example) and **Euro-Office DocumentServer**, which was chosen: a legitimate, officially-published open-source project (`github.com/Euro-Office`, AGPL-3.0, a hard fork of ONLYOFFICE, backed by a real European industry coalition — IONOS, Nextcloud, EuroStack, XWiki, OpenProject, Soverin, Abilian, BTactic, Office.EU, Open-Xchange — first stable release June 2026).

This is **not** the same as `delmarguillen/euro-office-ocis` (a one-person GitHub repo found during initial research) — that repo is just a working demo wiring the genuine official Euro-Office image together with oCIS's built-in WOPI bridge; useful as a reference, not something to depend on directly. It has no version pinning and disables JWT/proof-key security, both fine for a local demo, not for this deployment.

oCIS ships its own WOPI bridge as a built-in service (`collaboration`, started via `owncloud/ocis collaboration server` — the same image as `drive-ocis`, different command). ownCloud's own official reference (`github.com/owncloud/ocis/deployments/examples/ocis_full/collabora.yml`) shows this bridge wired to Collabora; wiring it to Euro-Office instead follows the same shape but is **not officially documented anywhere** — every Euro-Office-specific wiring detail below is adapted from the DocumentServer's own docs plus the one working community example, not an official oCIS+Euro-Office guide (doesn't exist yet).

## Recommended command shape: `drive:office:init` / `remove` / `show`

New subfolder `app/Commands/Drive/Office/` (PSR-4: `App\Commands\Drive\Office\OfficeInitCommand` etc.), mirroring the existing `drive:ext:*` family's naming (`app/Commands/Drive/DriveExt*Command.php`) rather than folding into `drive:init` as new flags. Reasoning: document editing is optional/detachable middleware layered onto an *already-working* Drive — an oCIS install is fully functional without it (unlike Chat's MAS, which is integral to a working Element X login) — so per `feedback_no_hidden_flag_commands` (operator's memory) this needs its own real command family, not flags bolted onto `drive:init`. It should never force existing Drive installs to change and stays fully decoupled from `DriveInitCommand`/`DriveShowCommand` in v1.

- `drive:office:init {environment?} {--context=} {--domain=} {--no-plex} {--vpn-only} {--force}` — refuses to run unless Drive itself is already installed. Deploys, in order, each with its own `kubectl apply` + `kubectl rollout status` wait (mirrors how `ChatInitCommand::deployChat()` deploys `synapse` then conditionally `mas`/`admin` as separate manifests, never merged — `app/Commands/Chat/ChatInitCommand.php`):
  1. `k8s.drive.collaboration` (new) — the WOPI bridge.
  2. `k8s.drive.documentserver` (new) — Euro-Office DocumentServer itself.
  3. Re-render + re-apply the **existing** `k8s.drive.ocis` view with two new optional variables (secure-view-app registration + an `app-registry.yaml` ConfigMap mount, both `@if`-gated so a plain `drive:init` with no office layer renders byte-identical output to today), then roll `drive-ocis` — this is the step that actually activates document creation in the web UI.
- `drive:office:remove {environment?} {--context=} {--purge}` — tears down the two new Deployments/Services/Ingress, **and** re-renders `k8s.drive.ocis` with the office variables unset so oCIS stops advertising an editor that no longer exists. PVCs/secrets survive by default, full wipe behind `--purge` (same posture as `DriveRemoveCommand`).
- `drive:office:show {environment?} {--context=}` — live-cluster read only (no tool-registry dependency — `registerDeployedTool()` has no metadata slot, confirmed in `DriveInitCommand.php:150`), reports component status via Laravel Prompts `table()` per `feedback_laravel_prompts_table`.

## Component additions to `DriveTool::components()`

`app/Vendors/DriveTool.php` currently returns one PRIMARY component (`app/Data/ClusterToolComponentData.php` is the shared shape — already built to support tools with several components, see its docblock: "CHAT, GIT, and DESIGN have several"). Add two, both `WORKER` role (neither gets an independent OIDC/SMTP relationship, so `sharesPrimarySecret` stays false on both — their trust relationship is a separate WOPI JWT secret, not Drive's user-facing SSO client):

```php
new ClusterToolComponentData(
    key: 'collaboration', role: ClusterToolComponentRole::WORKER,
    deployment: $name('drive-collaboration'), container: 'collaboration',
    // stateless gRPC/HTTP bridge, no PVC, no backup entry
),
new ClusterToolComponentData(
    key: 'documentserver', role: ClusterToolComponentRole::WORKER,
    deployment: $name('drive-documentserver'), container: 'documentserver',
    backupVolume: true, backupPath: '/var/www/euro-office/Data',
    // JWT_SECRET + wopi_*.key live here — MUST persist across restarts or
    // the collaboration<->documentserver trust silently breaks (see below)
),
```

This is enough for the generic backup scanner (`app/Traits/InteractsWithBackup.php::backupVolumeTargets()`) to pick up `drive-documentserver`'s volume automatically — no special-casing needed, confirmed against how it already handles Chat's multi-component case.

## New manifests

- `resources/views/k8s/drive/collaboration.blade.php` — same `owncloud/ocis` image/tag already pinned for `drive-ocis` (no independent version to track), `command: ["ocis"], args: ["collaboration", "server"]`. Env: `COLLABORATION_HTTP_ADDR`/`COLLABORATION_GRPC_ADDR` (`0.0.0.0:9300`/`:9301`), `COLLABORATION_WOPI_SRC: http://drive-collaboration:9300`, `MICRO_REGISTRY_ADDRESS`/`GATEWAY_GRPC_ADDR`/`REVA_GATEWAY` pointing at `drive-ocis`'s internal gRPC ports (bare short Service DNS name, same namespace — mirrors `InteractsWithChat::readChatWiredMas()`'s `http://chat-mas:8080/` precedent), `COLLABORATION_APP_ADDR` set to the documentserver's public host, plus the new WOPI JWT trust secret. No PVC, internal-only Service — one line explaining *why* it reuses the oCIS image with a different command (no existing precedent for that in this repo, worth being explicit for the next reader).
- `resources/views/k8s/drive/documentserver.blade.php` — `image: ghcr.io/euro-office/documentserver:<TAG>` (tag TBD, see spikes below). Env: `JWT_ENABLED=true`, `JWT_SECRET` (from the new secret), `WOPI_ENABLED=true`, `EXAMPLE_ENABLED=false`, `ALLOW_PRIVATE_IP_ADDRESS=true` (needed to fetch documents from the internal `drive-ocis` URL). PVCs for `/var/www/euro-office/Data` (secrets/keys — **must** persist) and `/var/lib/euro-office/documentserver` (document cache); `emptyDir` is fine for logs/config. `livenessProbe`/`readinessProbe` on `GET /healthcheck` port 80. Own Service + Ingress (`--vpn-only` supported, same middleware Drive already uses via `ensureVpnMiddleware(ClusterTool::DRIVE, ...)`).
- `ocis.blade.php` changes — additive only, both gated behind new `@if`s so a plain `drive:init` is unaffected: the secure-view-app registration env var (exact name TBD, spike below) and a mounted `app-registry.yaml` ConfigMap (mechanism unconfirmed, spike below), following the exact ConfigMap-mount pattern the file already uses for `drive-ocis-csp`.

## Secrets

New `drive-office-secrets` Secret in `larakube-shared`, same generate-or-read-back mechanics as `drive-secrets` (`DriveInitCommand.php:86-119`): `wopi-jwt-secret` (`Str::random(48)`, ≥32 chars per Euro-Office's documented minimum) shared between `collaboration` and `documentserver`'s `JWT_SECRET`. **Deliberately not reusing** `drive-secrets`' existing `jwt-secret` key — that's oCIS's own internal service-to-service JWT, a different trust domain from the WOPI bridge's JWT despite the name collision; conflating them is exactly the kind of subtle bug this codebase's existing comments go out of their way to flag. Pushed to OpenBao conditionally (`DRIVE_OFFICE_WOPI_JWT_SECRET`) same as every other Drive secret.

Host: derived as a string prefix on Drive's own resolved host (`"office.{$host}"`), same idiom `ChatInitCommand::deployMas()` already uses for `$masHost = "mas.{$host}"` — no new `SharedClusterService` enum case needed. `--domain` override follows the existing `hostFromDomainOption()` convention every other `:init` command uses.

## Pre-implementation spikes — resolve against the live cluster before writing final code

These gate real structural choices (which contracts `documentserver` implements, whether a Commons DB component exists, exact env var correctness) — not busywork, genuinely expensive to unwind after the fact:

1. **Does Euro-Office DocumentServer need external Postgres/Redis/RabbitMQ, or does it bundle them (classic ONLYOFFICE CE all-in-one behavior)?** Conflicting docs found; the one known-working example (`delmarguillen/euro-office-ocis`) runs it as a single standalone container with none of those alongside it. Test: deploy the bare image with zero external DB/Redis and confirm `/healthcheck` + a real document save works. If external services ARE required, this becomes a Commons consumer (`HasCommonsDatabases`/`HasCommonsRedisKeys` per `feedback_maximize_enums`) with a `DATABASE`-role `bundledOnly` fallback component for `--no-plex`, mirroring `chat-mas-db` — otherwise that whole branch is dropped and `--no-plex` may not even be a meaningful flag on `drive:office:init`.
2. **Exact secure-view-app registration value** oCIS's `collaboration` service expects for a non-Collabora WOPI app (the Collabora example uses a fixed known app-ID string; Euro-Office's equivalent is unverified) — inspect the deployed `collaboration` pod's own config schema/defaults live.
3. **The `app-registry.yaml` "+ New Document" mechanism** — seen only in one uncorroborated community compose file. Deploy without it first and confirm/deny whether blank-document creation actually needs it before building the ConfigMap-mount plumbing.
4. **Current Euro-Office DocumentServer stable image tag** — this plan provisionally assumes `9.3.1`; re-check `ghcr.io/euro-office/documentserver` tags at implementation time per this repo's hard rule against pinning from memory (`feedback_check_latest_versions`), and check whether Euro-Office documents a minimum-supported oCIS version against the pinned `owncloud/ocis:8.0.6`.
5. **`GATEWAY_GRPC_ADDR`/`REVA_GATEWAY` internal port** on the pinned `owncloud/ocis:8.0.6` image — provisionally `9142` from general oCIS knowledge, confirm live before hardcoding in `collaboration.blade.php`.

## Critical files

- `app/Vendors/DriveTool.php` — add the two components
- `resources/views/k8s/drive/ocis.blade.php` — additive `@if`-gated changes
- `app/Commands/Drive/DriveInitCommand.php` — pattern reference (secret gen, host resolution, manifest render/apply/rollout sequence)
- `app/Traits/InteractsWithChat.php` — `readChatWiredMas()` is the exact cross-component secret/DNS wiring precedent to copy
- `app/Traits/InteractsWithBackup.php` — confirms new component picked up automatically, no changes needed here
- `app/Enums/ChatTool.php` — multi-component tool precedent (6 components, roles, `deployMas()`/`deployAdmin()` sequencing)
- `app/Data/ClusterToolComponentData.php` / `app/Enums/ClusterToolComponentRole.php`

## Verification (once spikes are resolved and code exists)

1. `drive:office:init` against the live cluster, confirm all three rollouts succeed and `drive-ocis` picks up the new env/ConfigMap without breaking existing SSO/WebDAV access.
2. Create a blank document from the oCIS web UI, edit and save it, confirm the file lands correctly in storage (S3NG or POSIX per `--no-plex`).
3. Restart the `drive-documentserver` pod and confirm the WOPI trust relationship survives (the exact regression the persistent `/var/www/euro-office/Data` volume exists to prevent).
4. `drive:office:remove`, confirm oCIS stops advertising the editor and a plain re-`drive:init` still renders/applies cleanly.
5. `./vendor/bin/pest --parallel`, `./vendor/bin/pint`, `./vendor/bin/phpstan` per `cli/CLAUDE.md`.

## Full research trail

`project_ocis_office_euro_office_plan.md` in the operator's Claude memory has the pointer summary; this file is the durable source of truth.
