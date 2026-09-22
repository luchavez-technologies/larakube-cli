# Plan · Canonical Resource Naming for `ClusterTool::ANALYTICS` (Umami)

**Status:** In Progress · **Owner:** larakube CLI (Mechanic jurisdiction) · **Started:** 2026-09-22  
**Home:** `cli/plans/active/analytics-canonical-resource-naming.md`  
**Governing ADRs:** ADR 0021 (Canonical Resource Naming), ADR 0024 (Tool Remove Is Safe By Default)

---

## 1. Context & Objectives

Migrate `ClusterTool::ANALYTICS` (Umami web analytics) to `ResourceNaming::CANONICAL`.

### Current State (Pre-Migration):
- Manifests use legacy fixed names: Deployment `analytics-umami`, Service `analytics`, Ingress `analytics`, Secret `analytics-secrets`.
- Database allocated as fixed `umami`.
- `AnalyticsRemoveCommand` uses hardcoded bash deletion and has a broken `usesBundledStorage` checking for `analytics-secrets`.
- Lacks standard LaraKube identity labels (`larakube.io/tool`, `larakube.io/component`, `larakube.io/instance`, `larakube.io/managed-by`).
- `toolNamingKnownDrift()` lists `analytics` as drifting.

### Target State (ADR 0021 & ADR 0024):
1. **Enum**:
   - `ClusterTool::ANALYTICS` moved to `ResourceNaming::CANONICAL`.
   - `ClusterTool::ANALYTICS` added to `hasInstanceAwareRemoval()`.
2. **Vendor**:
   - `AnalyticsTool`:
     - `canonicalComponentName()` is `'umami'`.
     - `canonicalDatabaseList()` returns `['umami']` (producing Commons Postgres DB `umami_{$dbInstance}`).
     - `dbSecretRef()` returns canonical secret name from `ToolInstance`.
     - `vpnMiddlewareTarget()` uses `ToolInstance`.
3. **Workloads & Views**:
   - `resources/views/k8s/analytics/shared.blade.php`:
     - Deployment & Service: `umami-{$instance}`.
     - Secret: `umami-secrets-{$instance}`.
     - Database URL: points to `umami_{$dbInstance}`.
     - Injects canonical LaraKube identity labels.
   - `resources/views/k8s/analytics/ingress.blade.php`:
     - Ingress name: `umami-{$instance}`.
     - Backend Service: `umami-{$instance}`.
     - Injects canonical identity labels.
4. **Commands**:
   - `AnalyticsInitCommand`:
     - Uses `ToolInstance::forHost` for database (`$names->database()`) and secret (`$names->secret()`).
     - Passes `$names->labels()` to `Kubectl::putSecret()`.
   - `AnalyticsRemoveCommand`:
     - Default `usesBundledStorage()` is `false` (requires `--purge` to drop DB per ADR 0024).
     - Teardown uses typed `ResourceRef` targets without legacy fallbacks.
5. **Trait**:
   - `InteractsWithAnalytics`:
     - `readAnalyticsSecret()` queries canonical `$toolInstance->secret()`.
     - `isAnalyticsInstalled()` checks canonical `$toolInstance->deployment()`, or `larakube.io/tool=analytics`.
6. **Presence Probe**:
   - `SharedClusterService::ANALYTICS` probe updated to `deployment -l larakube-tool=analytics -n larakube-shared`.
7. **Drift Graduation**:
   - Remove `'analytics'` from `toolNamingKnownDrift()` in `ToolNamingDriftTest.php`.
8. **Tests**:
   - Create `AnalyticsInitCommandTest.php` and `AnalyticsRemoveCommandTest.php`.
   - Update `ClusterToolComponentsTest.php` to expect `'analytics' => 'umami'`.
