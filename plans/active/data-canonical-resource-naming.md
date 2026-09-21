# Plan: Complete ClusterTool::DATA Migration to Canonical Resource Naming (Zero Instance-less Fallbacks)

**Status: COMPLETED (Verified & Passing)**  
**Date: 2026-09-21**

---

## 1. Goal & Architecture
Migrate `ClusterTool::DATA` (PocketBase and Directus) completely onto Canonical Resource Naming (`ResourceNaming::CANONICAL` per ADR 0021 and commit `8c9acfa`).

### Directives Enforced:
1. **Zero Instance-less Fallbacks**: An instance is always a host-derived slug, never empty or null (`ToolInstance::forInstance` strictly enforces `!empty($instance)`). All commands, manifests, traits, and tests resolve the instance and use canonical names exclusively.
2. **Zero Destructive Swapping**: In `data:init`, there is **no concept of engine swap**. If a host is already claimed by the other engine, the command warns and interactively prompts for an alternative host, or fails cleanly in non-interactive mode. Silent deletion of workloads (`tearDownOtherEngineForInstance`) was completely eliminated.

### Canonical Resource Matrix
| Resource | PocketBase (`pocketbase`) | Directus (`directus`) |
|---|---|---|
| **Deployment** | `pocketbase-{$instance}` | `directus-{$instance}` |
| **Service** | `pocketbase-{$instance}` | `directus-{$instance}` |
| **Ingress** | `pocketbase-{$instance}-ingress` | `directus-{$instance}` |
| **Credentials Secret** | `pocketbase-secrets-{$instance}` | `directus-secrets-{$instance}` |
| **SMTP Secret** | `pocketbase-smtp-{$instance}` | `directus-smtp-{$instance}` |
| **OIDC Secret** | `pocketbase-oidc-{$instance}` | `directus-oidc-{$instance}` |
| **PVC (Volume)** | `pocketbase-storage-{$instance}` | N/A (SQLite vs S3) |
| **ConfigMap** | `pocketbase-hooks-{$instance}` | N/A |
| **Postgres Database** | N/A (Embedded SQLite) | `directus_{$dbInstance}` |
| **S3 Storage Bucket** | N/A (Embedded SQLite) | `directus-storage-{$instance}` |
| **Identity Labels** | `larakube.io/managed-by: larakube`<br/>`larakube.io/tool: data`<br/>`larakube.io/component: pocketbase`<br/>`larakube.io/instance: {$instance}` | `larakube.io/managed-by: larakube`<br/>`larakube.io/tool: data`<br/>`larakube.io/component: directus`<br/>`larakube.io/instance: {$instance}` |

---

## 2. Completed File Changes

### Workload Manifests & Views
- `resources/views/k8s/data/directus.blade.php`: Injected identity labels (`larakube.io/*`) into Deployment metadata, Pod template metadata, and Service metadata. Passed labels to Ingress partial.
- `resources/views/k8s/data/pocketbase.blade.php`: Applied canonical naming (`pocketbase-hooks-{$instance}`, `pocketbase-storage-{$instance}`) and identity labels.
- `resources/views/k8s/data/ingress.blade.php`: Applied identity labels directly to Ingress metadata.

### Enums & Traits
- `app/Enums/ClusterTool.php`: Added `self::DATA` to `ResourceNaming::CANONICAL`.
- `app/Enums/DataTool.php`: `smtpEnv()`, `oidcEnv()`, and `dbSecretRef()` strictly delegate to `ToolInstance::forInstance`.
- `app/Traits/InteractsWithData.php`: `readDataSecret` strictly requires `$instance` and resolves via `ToolInstance::forInstance`.

### CLI Commands
- `app/Commands/Data/DataInitCommand.php`:
  - Removed `tearDownOtherEngineForInstance`.
  - Added non-destructive host collision check (interactive prompt for alternate host; non-interactive exception).
  - Uses `ToolInstance::forInstance` for all workload and secret names.
- `app/Commands/Data/DataRemoveCommand.php`:
  - Strictly derives `$instance` with zero bare fallbacks.
  - Declares `usesBundledStorage() => false`, ensuring Commons Postgres (`data_directus_{$dbInstance}`) and S3 bucket drop cleanly upon `--purge`.
- `app/Commands/Data/DataShowCommand.php`:
  - Reads data secret passing `$instance` and `$engine`.

### Tests Updated
- Unit: `ClusterToolLifecycleTest.php`, `ClusterToolComponentsTest.php`, `ClusterToolForDeploymentTest.php`.
- Feature: `DataInitCommandTest.php`, `DataRemoveCommandTest.php`, `DataShowCommandTest.php`, `PocketBaseDataInitTest.php`, `NamedToolInstanceTest.php`, `MailWireCommandTest.php`, `MailUnwireCommandTest.php`, `SsoWireCommandTest.php`, `SsoUnwireCommandTest.php`, `SecretsWireCommandTest.php`.

---

## 3. Verification & Test Suite Status
- **Pint**: Passed cleanly.
- **PHPStan**: 0 errors (`--memory-limit=1G`).
- **Pest (Parallel)**: **2,532 passed, 0 failed, 3 skipped**.
- **Live Cluster**: Legacy dangling resources (`pvc/data-pocketbase-pvc-data-luchtech-dev`, database `data_directus`, and secret `sso-app-data`) purged and verified.
