# Plan · Canonical Resource Naming for `ClusterTool::TASKS` (Planka)

**Status:** In Progress · **Owner:** larakube CLI (Mechanic jurisdiction) · **Started:** 2026-09-22  
**Home:** `cli/plans/active/tasks-canonical-resource-naming.md`  
**Governing ADRs:** ADR 0021 (Canonical Resource Naming), ADR 0024 (Tool Remove Is Safe By Default)

---

## 1. Context & Objectives

Migrate `ClusterTool::TASKS` (Planka task management) to `ResourceNaming::CANONICAL`.

### Current State (Pre-Migration):
- Manifests use legacy fixed names: Deployment `tasks-planka`, Service `tasks`, Ingress `tasks`, Secret `tasks-planka-secrets`.
- Database allocated as fixed `tasks_planka`.
- `TasksRemoveCommand` has a broken `usesBundledStorage()` checking for `tasks-planka-secrets` and uses bash string deletion.
- Lacks standard LaraKube identity labels (`larakube.io/tool`, `larakube.io/component`, `larakube.io/instance`, `larakube.io/managed-by`).
- `toolNamingKnownDrift()` lists `tasks` as drifting.

### Target State (ADR 0021 & ADR 0024):
1. **Enum (`ClusterTool.php` & `TaskTool.php`)**:
   - `ClusterTool::TASKS` moved to `ResourceNaming::CANONICAL`.
   - `ClusterTool::TASKS` added to `hasInstanceAwareRemoval()`.
   - `TaskTool::PLANKA`:
     - `canonicalComponentName()` is `'planka'`.
     - `canonicalDatabaseList()` returns `['planka']` (producing Commons Postgres DB `planka_{$dbInstance}`).
     - `dbSecretRef()` returns `['secret' => 'planka-secrets', 'key' => 'db-password']`.
     - `smtpEnv()` uses `ClusterTool::TASKS->deploymentName($instance, $this->value)` and `SecretKind`.
     - `vpnMiddlewareTarget()` uses `ToolInstance`.
2. **Workloads & Views**:
   - `resources/views/k8s/tasks/planka.blade.php`:
     - Deployment & Service: `planka-{$instance}`.
     - Secret: `planka-secrets-{$instance}`.
     - Database URL: points to `planka_{$dbInstance}`.
     - Injects canonical LaraKube identity labels (`larakube-tool: tasks`, `app: ...`, `larakube.io/*`).
   - `resources/views/k8s/tasks/ingress.blade.php`:
     - Ingress name: `planka-{$instance}`.
     - Backend Service: `planka-{$instance}`.
     - Middleware: `{{ $names->namespace() }}-{{ $names->name('vpn-only') }}@kubernetescrd`.
     - Injects canonical identity labels.
3. **Commands**:
   - `TasksInitCommand`:
     - Uses `ToolInstance::forHost` for database (`$names->database()`) and secret (`$names->secret()`).
     - Passes `$names->labels()` to `Kubectl::putSecret()`.
     - Verifies rollout for `$names->deployment()`.
   - `TasksRemoveCommand`:
     - Remove `usesBundledStorage()` (defaults to `false`, requires `--purge` to drop DB per ADR 0024).
     - Teardown uses typed `ResourceRef` targets without legacy fallbacks.
4. **Trait**:
   - `InteractsWithTasks`:
     - `readTasksSecret()` queries canonical `$toolInstance->secret()`.
     - `isTasksInstalled()` checks canonical `$toolInstance->deployment()`, or `larakube.io/tool=tasks`.
5. **Presence Probe**:
   - `SharedClusterService::TASKS` probe updated to `deployment -l larakube-tool=tasks -n larakube-shared`.
6. **Drift Graduation**:
   - Remove `'tasks'` from `toolNamingKnownDrift()` in `ToolNamingDriftTest.php`.
7. **Tests**:
   - Update `ClusterToolComponentsTest.php` to expect `'tasks' => 'planka'`.
   - Update `ClusterToolLifecycleTest.php` to include `ClusterTool::TASKS` in `hasInstanceAwareRemoval()`.
   - Update `SecretsWireCommandTest.php` to expect `planka` static-role and restart `planka`.
   - Create `TasksInitCommandTest.php`.
