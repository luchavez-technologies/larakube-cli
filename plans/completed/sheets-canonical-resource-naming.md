# Plan · Canonical Resource Naming for `ClusterTool::SHEETS` (Teable)

**Status:** In Progress · **Owner:** larakube CLI (Mechanic jurisdiction) · **Started:** 2026-09-22  
**Home:** `cli/plans/active/sheets-canonical-resource-naming.md`  
**Governing ADRs:** ADR 0021 (Canonical Resource Naming), ADR 0024 (Tool Remove Is Safe By Default)

---

## 1. Context & Objectives

Migrate `ClusterTool::SHEETS` (Teable spreadsheet database) to `ResourceNaming::CANONICAL`.

### Current State (Pre-Migration):
- Manifests use legacy fixed names: Deployment `sheet-teable`, Service `sheet`, Ingress `sheet`, Secret `sheet-secrets`, `sheet-teable-smtp`, `sheet-teable-oidc`.
- Buckets: `sheet-public`, `sheet-private`.
- `SheetsRemoveCommand` uses hardcoded bash deletion string.
- Lacks standard LaraKube identity labels (`larakube.io/tool`, `larakube.io/component`, `larakube.io/instance`, `larakube.io/managed-by`).
- `toolNamingKnownDrift()` lists `sheets` as drifting.

### Target State (ADR 0021 & ADR 0024):
1. **Enum (`ClusterTool.php`)**:
   - `ClusterTool::SHEETS` moved to `ResourceNaming::CANONICAL`.
   - `ClusterTool::SHEETS` added to `hasInstanceAwareRemoval()`.
2. **Vendor (`SheetTool.php`)**:
   - `canonicalComponentName()` is `'teable'`.
   - `canonicalDatabaseList()` returns `['teable']` (producing Commons Postgres DB `teable_{$dbInstance}`).
   - `canonicalBucketList()` returns `['teable-public', 'teable-private']` (producing `teable-public-{$instance}`, `teable-private-{$instance}`).
   - `commonsRedisKeys()` returns `['teable']` (producing `teable_{$instance}`).
   - `dbSecretRef()` returns `teable-secrets` target.
   - `openbaoSyncConfig()` returns `teable-secrets` target.
   - `smtpEnv()` and `oidcEnv()` use `ClusterTool::SHEETS->deploymentName($instance)` and `SecretKind`.
   - `vpnMiddlewareTarget()` uses `ToolInstance`.
3. **Workloads & Views**:
   - `resources/views/k8s/sheet/teable.blade.php`:
     - Deployment & Service: `teable-{$instance}`.
     - Secret: `teable-secrets-{$instance}`.
     - Injects canonical LaraKube identity labels (`larakube-tool: sheets`, `app: ...`, `larakube.io/*`).
   - `resources/views/k8s/sheet/ingress.blade.php`:
     - Ingress name: `teable-{$instance}`.
     - Backend Service: `teable-{$instance}`.
     - Middleware: `{{ $names->namespace() }}-{{ $names->name('vpn-only') }}@kubernetescrd`.
     - Injects canonical identity labels.
4. **Commands**:
   - `SheetsInitCommand`:
     - Uses `ToolInstance::forHost` for database (`$names->database()`), buckets (`$names->bucket('teable-public')`, `$names->bucket('teable-private')`), redis (`$names->redisTenant()`), and secret (`$names->secret()`).
     - Passes `$names->labels()` to `Kubectl::putSecret()`.
     - Verifies rollout for `$names->deployment()`.
   - `SheetsRemoveCommand`:
     - Default `usesBundledStorage()` is `false` (requires `--purge` to drop DB and buckets per ADR 0024).
     - Teardown uses typed `ResourceRef` targets without legacy fallbacks.
5. **Trait**:
   - `InteractsWithSheet`:
     - `readSheetSecret()` queries canonical `$toolInstance->secret()`.
     - `isSheetInstalled()` checks canonical `$toolInstance->deployment()`, or `larakube.io/tool=sheets`.
6. **Presence Probe**:
   - `SharedClusterService::SHEET` probe updated to `deployment -l larakube-tool=sheets -n larakube-shared`.
7. **Drift Graduation**:
   - Remove `'sheets'` from `toolNamingKnownDrift()` in `ToolNamingDriftTest.php`.
8. **Tests**:
   - Update `ClusterToolComponentsTest.php` to expect `'sheets' => 'teable'`.
   - Update `ClusterToolLifecycleTest.php` to include `ClusterTool::SHEETS` in `hasInstanceAwareRemoval()`.
   - Update `ToolRemoveCommandTest.php` to expect `teable-public` and `teable-private` buckets.
   - Create `SheetsInitCommandTest.php`.
