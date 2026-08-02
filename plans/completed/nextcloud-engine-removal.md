# Nextcloud Engine Removal Plan

oCIS has fulfilled all our Drive needs, so the Nextcloud engine is being removed.
Drive becomes a single-engine tool (`ocis`) exactly like Desk (`freescout`). The
generic dual-engine machinery (`ClusterTool::engines()`, `defaultEngine()`,
`SharedClusterService::templatePayload()`) stays because FLOW, TASKS, and CHAT
still use it — only DRIVE's engine is dropped.

No live-cluster migration is needed: production is already oCIS (`drive-ocis`),
SSO wiring is ocis-only, and Nextcloud was never deployed to fulfill needs.

## 1. `cli/app/Enums/ClusterTool.php`

- **`getLabel()`** (line 27): `'Cloud Storage & Sync (Nextcloud or oCIS)'` → `'Cloud Storage & Sync (oCIS)'`.
- **`engines()`** (line 239): `self::DRIVE => ['ocis' => 'oCIS', 'nextcloud' => 'Nextcloud']` → `self::DRIVE => ['ocis' => 'oCIS']`. `defaultEngine()` (line 250) automatically resolves to `'ocis'`.
- **`commonsDatabaseList()`** (line 1202): remove `self::DRIVE => ['drive']` → `[]`. oCIS never leases a Postgres tenant (only Nextcloud did). This makes `dropCommonsTenants()` short-circuit and removes the DB line from every remove warning.
- **`commonsRedisKeys()`** (line 219): remove `self::DRIVE => ['drive']` → `[]` (Redis index was Nextcloud-only).
- **`dbSecretRef()`** (line 1186): remove `self::DRIVE => [...]` → `null`. oCIS has no Commons `db-password`; `secrets:wire` then excludes Drive from `$capable` (line 86) cleanly.
- **`smtpEnv()` / `oidcEnv()` / `deploymentName()`**: no change — already ocis-only (`drive-ocis-oidc`, `drive-ocis`).

## 2. `cli/app/Enums/SharedClusterService.php`

- **`templatePayload()`** (lines 68-70): drop the `kubectl get deployment drive-nextcloud` probe → `self::DRIVE => ['engine' => 'ocis']`. `InteractsWithTraefik::applySharedService()` still feeds `engine` into the shared ingress render.
- **`presenceProbe()`** (line 200): `'deployment -l "app in (drive-nextcloud, drive-ocis)" -n larakube-shared'` → `'deployment drive-ocis -n larakube-shared'`.

## 3. `cli/app/Commands/Drive/DriveInitCommand.php`

Model the single-engine behavior on `DeskInitCommand` (lines 141-150):
- **Signature** (line 36): `{--engine= : The drive engine to deploy ("ocis")}` (kept for script back-compat).
- **`resolveEngine()`** (lines 250-265): drop the `select` prompt; error on anything but `ocis`:
  ```php
  $explicit = strtolower((string) $this->option('engine'));
  if ($explicit !== '' && $explicit !== 'ocis') {
      $this->laraKubeError("Unknown drive engine '{$explicit}'. Supported: ocis.");
      exit(1);
  }
  return 'ocis';
  ```
- **`deployDrive()`** (lines 76-114): delete the `nextcloud` branch (`ensureCommons(['postgres','redis'])`, `allocateDatabase`, `allocateCommonsRedisIndex`) — keep only the SeaweedFS oCIS branch.
- **`$dbPassword`** (line 76): always `null`. Remove the `--from-literal=db-password=` append (lines 139-141) and the `DRIVE_DB_PASSWORD` OpenBao push (lines 155-157).
- **Manifest render** (line 161): `view("k8s.drive.{$engine}")` → `view('k8s.drive.ocis')`.
- **Lines 176-177**: `$engineName = 'oCIS'; $deployName = 'deploy/drive-ocis';` (or inline the literals).
- **Description** (line 39): → `'Deploy the oCIS cloud storage and sync stack into larakube-shared'`.

## 4. `cli/app/Commands/Drive/DriveRemoveCommand.php`

- **`teardownWarning()`** (lines 16-24): `"Drive (oCIS or Nextcloud) will be REMOVED..."` → `"Drive (oCIS) will be REMOVED..."`; drop the `'Commons Postgres database + Redis index (Nextcloud engine only)'` line (line 21).
- **`usesBundledStorage()`** (lines 26-33): delete the override. With `commonsDatabaseList()` empty, the base `dropCommonsTenants()` short-circuits at `$databases === []`; the `drive-nextcloud` probe is dead code.
- **`teardown()`** (lines 35-45): KEEP the `deployment/drive-nextcloud service/drive-nextcloud ingress/drive-nextcloud` delete list — this is the one intentionally-remaining Nextcloud reference: it idempotently (`--ignore-not-found`) cleans stale resources on pre-removal clusters, consistent with the base class's "remove every artifact the tool ever created" contract. Update the comment (line 37-38) from "the engine is switchable" to "the legacy Nextcloud engine is cleaned up for pre-removal clusters." (Alternative if you want zero Nextcloud references: drop them and leave stale cleanup to a one-time manual `kubectl delete`.)
- **`SecretsWireCommand` note**: the comment at lines 157-161 about Drive's phantom-role skip is now stale — Drive is excluded via `dbSecretRef() === null`; simplify the comment (keep the generic empty-password skip logic for other tools).

## 5. Manifests

- **Delete** `cli/resources/views/k8s/drive/nextcloud.blade.php`.
- **Keep** `cli/resources/views/k8s/drive/ocis.blade.php` (includes `k8s.drive.ingress` with hardcoded `'engine' => 'ocis'`, line 328) and `ingress.blade.php` (still `$engine`-parameterized, now always `'ocis'`).
- Compiled view cache in `cli/storage/framework/views/` regenerates on next run — no action.

## 6. Tests

- **`cli/tests/Feature/DriveInitCommandTest.php`**: delete the Nextcloud test (lines 26-44). Keep the oCIS test. Add a rejection test: `--engine=nextcloud` → non-zero exit + "Unknown drive engine 'nextcloud'".
- **`cli/tests/Feature/DriveRemoveCommandTest.php`**:
  - Test 1 (lines 5-22): remove the `'*get deployment drive-nextcloud*'` fake and its comment; assertions unchanged (commonsDatabases is empty → drop always skipped).
  - Test 2 (lines 24-37): delete (Nextcloud engine gone).
  - Test 3 (lines 39-52): remove the `'*get deployment drive-nextcloud*'` fake; assertions unchanged.
- **`cli/tests/Unit/ClusterToolLifecycleTest.php`**:
  - Line 67-79 invariant: DRIVE engines `['ocis']`, default `'ocis'` ∈ keys ✓ — passes unchanged.
  - Line 85-99 invariant ("--no-plex ⇒ non-empty commonsDatabases"): Drive now fails (oCIS's Commons lease is the SeaweedFS bucket, not a Postgres tenant). Exempt DRIVE with a comment.
- **`cli/tests/Feature/SecretsWireCommandTest.php`** (line 214): `--tool=drive` now errors with `'drive' does not have a Commons database password OpenBao can rotate.` (SecretsWireCommand line 105) instead of the skip warning — update the assertion; keep `Http::assertNotSent(... static-roles/drive)`.

## 7. Docs

- Move `cli/plans/active/drive-integration.md` → `cli/plans/completed/drive-integration.md` (the dual-engine chapter is closed).
- No Nextcloud references exist in the Docusaurus `docs/` site.

## 8. Verification (battle-test)

From `cli/`: `./php vendor/bin/pint`, `./php vendor/bin/phpstan --memory-limit=1G`, `./php vendor/bin/pest`. Then the user runs `./build` (per CLAUDE.md, never run it myself) and verifies live: `larakube drive:init local --force`, `larakube drive:remove local --force`, `larakube secrets:wire local --all --force`, `larakube sso:wire --tool=drive` (still ocis-only).

## 9. Addendum — sso:grant / sso:revoke Drive-role rollout (completed)

The oCIS-only transition surfaced that Drive's sole grantable role (`ocisAdmin`) lives on the SHARED Zitadel project (`LaraKube Shared Tools`), not the RBAC project (`LaraKube RBAC`) — while the role-gated tools (secrets, monitor) grant on the RBAC project. The SSO pair had to know that.

- **`sso:grant`** (`InteractsWithSsoGrants::resolveGatedTool()`): the picker no longer probes live role lists on the RBAC project. It offers EVERY tool declaring `grantableRoles()` (secrets, monitor, drive) so a new operator can discover Drive without knowing `--tool`. A grant for a role the cluster hasn't wired yet fails loudly against Zitadel instead of being hidden.
- **`sso:revoke`** (`SsoRevokeCommand::resolveRolesFromCurrentAccess()`): the discovery picker now aggregates roles from BOTH projects (RBAC + shared), and every `$toRevoke` item carries its owning `role`/`projectId`/`projectName` so the revoke targets the right project. The old single-project discovery never surfaced `ocisAdmin`, which made it unreachable without `--role`. The `--role` path resolves the owning tool via `ClusterTool::forGrantableRoleKey()` and its project via `resolveSsoProject()`.
- Tests: `SsoRevokeCommandTest` adds a body-aware cross-project discovery test (asserts the picker offered both `openbao-admin` and `ocisAdmin` and deleted the `ocisAdmin` grant on the shared project); `SsoGrantCommandTest` locks the no-live-probe picker.
