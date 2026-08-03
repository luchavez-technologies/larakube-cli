# Plan: Stop Shipping Runtime Secrets to CI Providers (`dotenv:push` & `dotenv:diff`)

**Status:** Finalized Plan / Ready for Implementation
**Created:** 2026-07-29
**Updated:** 2026-08-03 (post grill-me gap analysis & user alignment)
**Target Version:** LaraKube CLI v1.2.0

---

`cloud:configure` base64-encodes the **entire** `.env.{env}` into a single CI
secret, and the generated workflow turns it back into a file that does two
different jobs at once — feeding the Vite build AND becoming the app's runtime
Kubernetes Secret. The consequence is that every production credential the app
has (`APP_KEY`, `DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, `REVERB_APP_SECRET`,
`MEILISEARCH_KEY`) sits in GitHub, and the whole file is the unit of
granularity — rotating one key means re-uploading all of them.

Split those two jobs apart, and stop the runtime half from ever reaching the CI
provider.

## 📍 Current flow (what we're replacing)

```
larakube cloud:configure {env}
  └─ uploadGhaSecrets()                      app/Traits/ConfiguresCloudEnvironment.php:643
       └─ base64(.env.{env}) ──> gh secret set {ENV}_ENV_FILE_BASE64

CI workflow                                  resources/views/k8s/cloud-pilot-deploy.blade.php
  ├─ decode {ENV}_ENV_FILE_BASE64 ──> .env
  ├─ docker build --secret id=dotenv,src=.env     (lines 180 / 204 / 285)
  │    └─ consumed by resources/views/docker/php.blade.php:65 for `npm run build`
  └─ kubectl create secret generic laravel-secrets \
       -n {namespace} --from-env-file=.env        (line 364)
```

Pods consume the result via `envFrom` in `resources/views/k8s/base/deployment.blade.php`:
a `configMapRef` on `laravel-config` plus a `secretRef` on `laravel-secrets`.

## 🧩 The constraint that shapes the whole design

The Vite build genuinely needs environment values at **image-build time**, and
those `VITE_*` values are compiled into a public JS bundle — they were never
secret. But the build currently mounts the entire `.env`, secrets included.

So this is not "move secrets out of CI." It is **split the file in two**:

| Build-time, public                        | Runtime, secret                              |
| ----------------------------------------- | -------------------------------------------- |
| `VITE_*`, `APP_URL`, `ASSET_URL`          | `APP_KEY`, `DB_PASSWORD`, `AWS_SECRET_*`     |
| Compiled into a public bundle anyway      | `REVERB_APP_SECRET`, `MEILISEARCH_KEY`, …    |
| Safe to keep in the workflow              | Must never reach the CI provider             |

**The classification already exists.** Every component enum implements both
`getPublicEnvironmentVariables()` and `getSecretEnvironmentVariables()`
(`ScoutDriver`, `StorageDriver`, `DatabaseDriver`, `CacheDriver`,
`LaravelFeature`, `ServerVariation`, `Blueprint`). The hard part of this
problem is done — the split should be derived from those, not from a new
hand-maintained allowlist.

## 🚧 Scope boundary — what this plan does NOT cover

The **ESO + OpenBao** stream has been completed and owns:

- deploying OpenBao and the External Secrets Operator,
- `ClusterSecretStore/openbao` wiring ESO to OpenBao,
- `ExternalSecret` CRDs per tool (forgejo, stalwart, sso, vaultwarden, sign, record, webmail, etc.),
- all legacy Infisical references purged from the codebase: alias methods, blade templates,
  CRDs, operator manifests, and variable names.

This plan must **dovetail with that work, not duplicate it**. Everything below
is backend-agnostic and can land before OpenBao exists.

Cluster state after the migration: `larakube-secrets` runs `openbao-backend` +
`external-secrets`; `ClusterSecretStore/openbao` is `Ready: True`; per-tool
`ExternalSecrets` sync production secrets into each tool's namespace.

---

## 🔄 OpenBao Automatic Database Rotation Integration

When OpenBao is bootstrapped in `larakube-secrets`:

```
  ┌─────────────────────────────────────────────────────────────┐
  │                   OpenBao Secret Engine                     │
  │  1. OpenBao Static Database Role (`registerStaticRole()`)   │
  │     OpenBao rotates DB_PASSWORD in PostgreSQL directly      │
  │                          │                                  │
  │                          ▼                                  │
  │  2. OpenBao updates `secret/data/{env}/{app}/DB_PASSWORD`   │
  └──────────────────────────┬──────────────────────────────────┘
                             │
                             ▼
  ┌─────────────────────────────────────────────────────────────┐
  │          External Secrets Operator (ESO Sync)               │
  │  3. ESO detects OpenBao change within 60s                   │
  │     Updates K8s Secret `laravel-secrets` in {app}-{env}     │
  │                          │                                  │
  │                          ▼                                  │
  │  4. Reloader Controller triggers zero-downtime rollout      │
  │     New pod boots → passes /up health check → old pod stops │
  └─────────────────────────────────────────────────────────────┘
```

1. **Static Database Roles**: LaraKube registers static database roles in OpenBao via `registerStaticRole($kubectl, $roleName, $dbEngine, $dbName)`. OpenBao takes ownership of the database password inside PostgreSQL/MySQL and rotates it automatically on a schedule or via `larakube plex:rotate`.
2. **Zero-Downtime ESO Sync**: OpenBao writes the new password to `secret/data/{app}/{environment}/DB_PASSWORD`. ESO detects the change within 60s and updates `laravel-secrets` in `{app}-{environment}`. Reloader performs a rolling update, passing `/up` health checks before cutting over traffic.
3. **Local Synchronization (`secrets:pull`)**: Developers running `larakube secrets:pull {environment} --app=my-app` fetch the latest active rotated password back into `.env.{environment}` for local development and recovery.

---

## 🌐 Multi-Project & Multi-Environment Scoping Architecture (App-First: `secret/data/{app}/{environment}/`)

Secrets are stored in OpenBao using an **App-First 3-level deterministic path structure**:

### Deterministic Command Scoping:
- **`larakube secrets:push [environment]`**: Inside a project directory, `--app` is optional and defaults to `$config->name` from `.larakube.json`.
- **`larakube secrets:push production --app=my-store-locator`**: Outside a project directory, `--app=name` explicitly targets a specific application repository.
- **Namespace Isolation**: Each environment (`my-laravel-app-staging`, `my-laravel-app-production`) deploys an `ExternalSecret` custom resource bound specifically to its path (`secret/data/{app}/{environment}`). Cross-tenant or cross-environment secret leaks are cryptographically impossible.

---

### 🛡️ App & Environment-Scoped OpenBao UI RBAC (`secrets:grant` & `secrets:revoke`)

LaraKube enforces strict per-application and per-environment access policies in the OpenBao Web UI (`secrets.{domain}`). Using `--only`, `--except`, and `--role`, admins control exactly which environments a developer can access, and whether they have Read-Write or Read-Only capabilities:

```bash
# Grant Read-Write access (create, read, update, patch) ONLY to staging secrets (default role=developer)
larakube secrets:grant [environment?] --email=junior@example.com --only=staging

# Grant Read-Only access (read, list) to staging secrets (contractor/auditor)
larakube secrets:grant [environment?] --email=auditor@example.com --only=staging --role=viewer

# Grant full Read-Write access across all environments EXCEPT production
larakube secrets:grant [environment?] --email=dev@example.com --except=production

# Grant full Admin access across all environments
larakube secrets:grant [environment?] --email=lead@example.com --role=admin
```

#### OpenBao Policy Matrix by Role:

| Role (`--role=`) | Path Scope | Granted HCL Capabilities | What the User Can Do |
| :--- | :--- | :--- | :--- |
| **`developer`** (default) | `secret/data/{app}/{env}/*` | `["create", "read", "update", "patch", "list"]` | **Full Read-Write**: Can view, create, update, and patch keys in their allowed environments (e.g. `staging`). Cannot delete keys or access unauthorized envs (e.g. `production`). |
| **`viewer`** | `secret/data/{app}/{env}/*` | `["read", "list"]` | **Read-Only**: Can view and pull secrets (`secrets:pull`) into local `.env`, but cannot push, modify, or create keys in OpenBao. |
| **`admin`** | `secret/data/{app}/*` | `["create", "read", "update", "patch", "delete", "list"]` | **Full Admin**: Can manage all environments, create/delete keys, and rotate static credentials. |

**Generated HCL Policy for `--role=developer --only=staging` (`policy-app-my-laravel-app-staging`):**
```hcl
# Scoped strictly to staging path with full Read-Write permissions
path "secret/data/my-laravel-app/staging/*" {
  capabilities = ["create", "read", "update", "patch", "list"]
}
path "secret/metadata/my-laravel-app/staging/*" {
  capabilities = ["read", "list"]
}
```

When `junior@example.com` logs into OpenBao UI via Zitadel SSO, OpenBao **grants them full Read-Write access to `my-laravel-app/staging/`** so they can push, edit, and patch staging secrets freely, while `my-laravel-app/production/` remains completely hidden!

---

## 🚀 Onboarding & Automated `.env` Seeding (`secrets:pull`)

When developers run **`larakube clone <repo>`** or **`larakube init`**:
1. LaraKube checks if OpenBao or cluster credentials exist for the project.
2. LaraKube prompts: `"Sync environment secrets from cluster/OpenBao for 'staging'?"`
3. Running **`larakube secrets:pull [environment]`** pulls active keys from `secret/data/{app}/{environment}` (or cluster Secret `laravel-secrets`) and seeds a 100% working `.env.{environment}` locally!

---

## 📐 Standard Command Signatures (`{noun}:{verb} <environment?> --flag1=value1`)

The local env file is the **source of truth**; the cluster and any secrets backend are downstream copies. All secret operations follow LaraKube's mandatory `{noun}:{verb} <environment?> --flag1=value1` signature convention (`--app` is optional when run inside a project):

| Command Signature | Description |
| :--- | :--- |
| `larakube secrets:push [environment?] [--app=name]` | `.env.{environment}` secret keys → cluster Secret (and OpenBao when present) |
| `larakube secrets:pull [environment?] [--app=name]` | cluster Secret/OpenBao → `.env.{environment}`, for onboarding & recovery |
| `larakube secrets:diff [environment?] [--app=name]` | Read-only drift report, exits non-zero (1) when file and cluster disagree |
| `larakube secrets:grant [environment?] --email=user [--only=envs] [--except=envs]` | Grants app & environment-scoped OpenBao UI RBAC policy to a user |
| `larakube secrets:revoke [environment?] --email=user [--only=envs] [--except=envs]` | Revokes app & environment-scoped OpenBao UI RBAC policy from a user |

`secrets:diff` is the pre-flight check: once the file is authoritative, the failure mode is silent divergence — someone edits a Secret with `kubectl`, or a backend rotates a value, and the file no longer describes reality. It compares **keys and value-equality**, never printing plaintext values — only `same / differs / missing in file / missing in cluster` per key.

---

## 🔧 Phase 1 — `larakube secrets:push [environment]`

Write `laravel-secrets` into the target namespace directly from the developer's
machine, keyed **individually** rather than via `--from-env-file`, so a single
key can be rotated without rewriting the rest.

- Source the secret half from `getSecretEnvironmentVariables()` across
  `$config->getComponents($environment)`, not by parsing the whole env file.
- Honour the existing lock mechanism (`$config->isLocked($envFile)`) and the
  Plex ownership marker — `ConfigData::isPlexBacked()` already declares which
  components' connection env is owned by `plex:join` and must not be recomputed.
- Remove the `kubectl create secret` line from the workflow template once this
  lands (`cloud-pilot-deploy.blade.php:364`, and the GitLab twin in
  `cloud-pilot-deploy-gitlab.blade.php`).

**Ordering hazard.** The Secret must exist *before* manifests apply, or pods
CrashLoop on a missing `envFrom` source. `cloud:deploy` should assert its
presence and fail fast with a message naming `dotenv:push`, rather than letting
it surface as an opaque crashloop several minutes later.

## 🔧 Phase 2 — shrink the CI secret to build-only config

Replace `{ENV}_ENV_FILE_BASE64` with a build-only, public subset:

- Keep: `VITE_*`, `APP_URL`, `ASSET_URL` — everything the asset build needs.
- Drop: everything `getSecretEnvironmentVariables()` reports.
- `KUBECONFIG` **stays** — CI still needs cluster access to apply manifests.

Note that `collectViteBuildArgs()`
(`app/Traits/InteractsWithRemoteDeploy.php:130`) and the workflow's
`echo "VITE_… " >> .env` block already append the correct per-env values *after*
the file contents; dotenv's last-key-wins is what makes that work. With the file
reduced to public config, that append stops being load-bearing and becomes a
belt-and-braces default.

**This is the phase that actually removes production credentials from GitHub**,
and it does not depend on which secrets backend wins.

## 🔧 Phase 3 — hand-off seam to ESO / OpenBao

OpenBao is live on the production cluster and ESO is syncing secrets. `dotenv:push`
grows one branch:

- **Backend present** → write to OpenBao; let `ClusterSecretStore` +
  `ExternalSecret` materialise `laravel-secrets` in the namespace.
- **No backend** → Phase 1's direct Secret write, unchanged.

Same command, same UX, and the direct write survives as the permanent
no-backend fallback (air-gapped installs, local clusters, single-node VPS).
A user without OpenBao should never see the word OpenBao — the backend is an
implementation detail of `dotenv:push`, not a concept they have to learn.

`dotenv:diff` gains a third column in this phase (file / cluster / backend), so
a backend that has drifted from the Secret it feeds is visible too.

## 🔄 Migration complete — OpenBao has replaced Infisical

The migration is done: `secrets:init` now deploys OpenBao directly (no Infisical
operator, no Infisical CRDs, no Infisical app manifests). Existing secrets were
re-pushed from `.env.{env}` files into OpenBao via the `pushClusterSecret` /
`syncClusterSecretToNamespace` path. No data was lost.

## ⚖️ Resolved Decisions (Grill-Me Alignment)

1. **Fallback when OpenBao is absent**:
   `dotenv:push` checks `isOpenBaoBootstrapped()`. If OpenBao is present, it calls `pushClusterSecret()`. If OpenBao is absent (e.g. single-node VPS, local k3s/OrbStack), `dotenv:push` writes directly to the Kubernetes Secret `laravel-secrets` via `kubectl create secret generic laravel-secrets -n {namespace} ... --dry-run=client -o yaml | kubectl apply -f -`. Zero OpenBao dependency required.
2. **`heal` vs `dotenv:push` boundaries**:
   - `heal` owns derived architectural keys (`APP_URL`, `ASSET_URL`, `VITE_*`, hosts, ports).
   - `dotenv:push` / `dotenv:pull` owns secret credentials (`APP_KEY`, `DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, `REVERB_APP_SECRET`).
3. **CI Secret Reduction**:
   `uploadGhaSecrets()` uploads only `{$ENV}_BUILD_ENV_BASE64` (public `VITE_*` and build variables). `{ENV}_ENV_FILE_BASE64` is removed, preventing runtime credentials from entering GitHub Secrets.

---

## 📋 Implementation Tasks

- [ ] Create `app/Commands/DotenvPushCommand.php` (`larakube dotenv:push [env]`)
  - Parses `.env.{env}`
  - Filters secret keys using `getSecretEnvironmentVariables()`
  - If OpenBao present: calls `pushClusterSecret()`
  - If OpenBao absent: executes direct K8s Secret apply for `laravel-secrets`
- [ ] Create `app/Commands/DotenvDiffCommand.php` (`larakube dotenv:diff [env]`)
  - Compares local `.env.{env}` keys against cluster Secret/ConfigMap
  - Outputs a key-level drift table (`same / differs / missing in file / missing in cluster`) without printing values
  - Exits non-zero (1) if divergent
- [ ] Update `app/Traits/ConfiguresCloudEnvironment.php`:
  - Modify `uploadGhaSecrets()` to upload `{$ENV}_BUILD_ENV_BASE64` containing public build variables only
  - Remove production secret credentials from GitHub Secret payload
- [ ] Update GHA Blade template (`resources/views/k8s/cloud-pilot-deploy.blade.php`):
  - Consume `{$ENV}_BUILD_ENV_BASE64` for `docker build`
  - Remove `kubectl create secret generic laravel-secrets --from-env-file=.env` line
- [ ] Create Pest unit and feature tests:
  - `tests/Feature/DotenvPushCommandTest.php`
  - `tests/Feature/DotenvDiffCommandTest.php`
- [ ] Format with `./php vendor/bin/pint` and verify PHPStan (0 errors)

---

## ✅ Definition of done

- No credential reported by `getSecretEnvironmentVariables()` is stored in any
  CI provider secret.
- `larakube dotenv:push {env}` creates/updates `laravel-secrets`, and
  `cloud:deploy` refuses to proceed when it is absent.
- `larakube dotenv:diff {env}` reports drift without printing values, and exits
  non-zero when the file and the cluster disagree.
- The workflow templates no longer create the runtime Secret from a file.
- Existing single-node / no-backend installs keep working with no extra
  components deployed, and never encounter the word "OpenBao".
