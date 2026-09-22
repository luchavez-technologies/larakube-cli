# PhpStorm Handoff & Next Steps: Canonical Resource Naming

**Active Work Context & Roadmap for Continuing in PhpStorm**  
**Date:** 2026-09-21

---

## 1. Quick Terminal Commands (PhpStorm Terminal)

```bash
# Code Quality, Formatting & Static Analysis
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=1G

# Fast Parallel Pest Suite (2,532 tests, ~10s)
./vendor/bin/pest --parallel

# Targeted DATA Tests
./vendor/bin/pest tests/Feature/DataInitCommandTest.php tests/Feature/DataRemoveCommandTest.php tests/Feature/PocketBaseDataInitTest.php

# Rector (Automatic Refactoring)
./vendor/bin/rector process
```

> [!NOTE]
> If you want to build and test the CLI binary locally or against the cluster, run `./build` manually in your PhpStorm terminal.

---

## 2. Completed State: `ClusterTool::DATA` (PocketBase & Directus)

The migration of `DATA` to `ResourceNaming::CANONICAL` (ADR 0021) is 100% complete and verified:

- **Enums & Traits:**
  - `ClusterTool::DATA` is in `ResourceNaming::CANONICAL`.
  - `DataTool.php` delegates `smtpEnv()`, `oidcEnv()`, and `dbSecretRef()` strictly to `ToolInstance::forInstance`.
  - `InteractsWithData.php` strictly requires `$instance` in `readDataSecret()`.
- **Workloads & Views:**
  - `resources/views/k8s/data/directus.blade.php`: Identity labels applied across Deployment, Pod template, Service, and passed to Ingress.
  - `resources/views/k8s/data/pocketbase.blade.php`: ConfigMap (`pocketbase-hooks-{$instance}`) and PVC (`pocketbase-storage-{$instance}`) bind strictly to canonical naming with identity labels.
  - `resources/views/k8s/data/ingress.blade.php`: Applied identity labels directly.
- **Safety Standard (Zero Destructive Swapping):**
  - Completely deleted `tearDownOtherEngineForInstance` in `DataInitCommand.php`.
  - Host collisions are now handled safely: interactive mode prompts for an alternative host; non-interactive mode throws an exception.
- **Removal & Purge:**
  - `DataRemoveCommand.php` has zero bare fallbacks, strictly derives `$instance`, and `usesBundledStorage() => false`, ensuring Commons Postgres (`data_directus_{$dbInstance}`) and S3 bucket drop cleanly upon `--purge`.
- **Live Cluster Status (`larakube-159.89.205.239`):**
  - Old legacy artifacts (`pvc/data-pocketbase-pvc-data-luchtech-dev`, database `data_directus`, and secret `sso-app-data`) were purged and confirmed clean.

---

## 3. Immediate Actions in PhpStorm

### Action 1: Live Verification (Optional before commit)
To verify the freshly built binary on the cluster:
```bash
./build
larakube data:init --engine=pocketbase --domain=data.luchtech.dev
larakube data:show
larakube data:remove --domain=data.luchtech.dev --purge
```

### Action 2: Git Commit
Stage and commit the 22 modified files and ADR/plan docs:
```bash
git add app/Commands/Data/ app/Enums/DataTool.php app/Enums/ClusterTool.php \
        app/Traits/InteractsWithData.php resources/views/k8s/data/ \
        tests/Feature/Data* tests/Feature/PocketBase* tests/Feature/*Wire* \
        tests/Unit/ClusterTool* docs/decisions/ plans/
git commit -m "feat(data): complete migration to canonical resource naming (ADR 0021)"
```

---

## 4. Next Tool Migration Candidates (Fleet Canonical Naming)

According to `ClusterTool::resourceNaming()` (line 1342 of `app/Enums/ClusterTool.php`), the remaining companion services belong to two categories:

### Group A: `AS_SHIPPED` (Legacy Bare Names)
| Tool | Product | Status | Result |
|---|---|---|---|
| **`DATA`** | PocketBase / Directus | **DONE** (`8c9acfa`) | Canonical workloads, PVCs, and DB tenants (`data_directus_{$dbInstance}`). |
| **`LINK`** | Kutt | **DONE** (`ca4f924`) | Canonical `kutt-{$instance}`, `kutt-secrets-{$instance}`, DB `kutt_{$dbInstance}`. |
| **`ANALYTICS`** | Umami | **DONE** (`1bc8d1d`) | Canonical `umami-{$instance}`, `umami-secrets-{$instance}`, DB `umami_{$dbInstance}`. |
| **`SHEETS`** | Teable | **DONE** (`15d9e6c`) | Canonical `teable-{$instance}`, `teable-secrets-{$instance}`, buckets `teable-*-{$instance}`, DB `teable_{$dbInstance}`. |
| **`TASKS`** | Planka | **DONE** (`7150c52`) | Canonical `planka-{$instance}`, `planka-secrets-{$instance}`, DB `planka_{$dbInstance}`. |
| **`PASSWORDS`** | Vaultwarden | Next up | Master secrets (`ADMIN_TOKEN`, RSA keys) + PostgreSQL + PVC |
| **`SSO`** | Zitadel | Pending | PostgreSQL (`zitadel`) + Master keys + Domain mappings |
| **`CHAT`** | Matrix Synapse / MAS | Pending | PostgreSQL (`chat_matrix`, `chat_mas`) + Coturn + MAS admin |
| **`RESUME`** | Reactive Resume | Pending | PostgreSQL + Redis + S3 object backing |
| **`SUPPORT`** | Chatwoot | Pending | PostgreSQL + Redis + S3 object backing |

### Group B: `INSTANCE_SUFFIXED` (Hybrid)
| Tool | Product | Next Action |
|---|---|---|
| **`VPN`** | NetBird | Workload layer migration (`netbird-*` → `vpn-*-{$instance}`). Database `vpn_management_{$dbInstance}` is already done. |
| **`DRIVE`** | oCIS | Align storage PVC (`drive-ocis-storage-{$instance}`) and master crypto secrets. |
| **`CRM`** | Twenty | Align worker and server workloads with PostgreSQL tenants. |
| **`MAIL`** | Stalwart | Mail server + Stalwart data storage. |

---

## 5. Step-by-Step Blueprint for Migrating the Next Tool

When migrating the next tool (e.g. `LINK` or `VPN`):
1. **Move Enum to Canonical**: In `app/Enums/ClusterTool.php`, add `self::{TOOL}` to `ResourceNaming::CANONICAL` in `resourceNaming()`.
2. **Update Tool Vendor Enum**: In `app/Enums/{Tool}Tool.php`, ensure secret refs, database lists, and storage definitions use `ToolInstance::forInstance`.
3. **Update Manifest Blade Views**: In `resources/views/k8s/{tool}/*`:
   - Replace bare names with `{{ $deployName }}`, `{{ $serviceName }}`, `{{ $secretName }}`.
   - Inject identity labels:
     ```blade
     labels:
       @foreach($labels as $key => $value)
         {{ $key }}: {{ $value }}
       @endforeach
     ```
4. **Update Init & Remove Commands**:
   - In `{Tool}InitCommand.php`: Use `ToolInstance::forInstance` to derive names. Ensure no destructive deletes of other workloads.
   - In `{Tool}RemoveCommand.php`: Strictly require instance; verify `usesBundledStorage()` accurately drops Commons DB and S3 bucket on `--purge`.
5. **Update Tests**:
   - Update `{Tool}InitCommandTest.php` and `{Tool}RemoveCommandTest.php` to assert canonical naming.
   - Verify `ClusterToolLifecycleTest.php` and wire tests pass.
6. **Run Quality Gates**:
   - `./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel`
