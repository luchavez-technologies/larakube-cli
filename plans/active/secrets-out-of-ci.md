# Plan: Stop Shipping Runtime Secrets to CI Providers (`dotenv:push` & `dotenv:diff`)

**Status:** ✅ Code complete (2026-08-04) — pending manual/cluster verification (see `plans/testing-checklist.md`, "Secrets Out of CI" section)
**Created:** 2026-07-29
**Updated:** 2026-08-04 (implemented — see "✅ What actually shipped" below; several specifics changed from the design further down this doc)
**Target Version:** LaraKube CLI v1.2.0

---

## ✅ What actually shipped (2026-08-04)

Implemented end to end, `./php vendor/bin/pest` (1333 passing) + PHPStan clean.
The design evolved during implementation — **this section is the source of
truth; treat the rest of the document below as historical design rationale,
not a literal spec of the shipped commands.**

**Bigger win than originally scoped: zero `.env` content reaches CI, not just
a shrunk public subset.** `cloud:configure:gha`/`:gitlab` now bake every
public/build var as a literal `echo 'KEY=VALUE' >> .env` line directly into
the generated workflow file, computed from the blueprint at generation time
(`ConfiguresCloudEnvironment::buildPublicEnvScript()`). No `{ENV}_ENV_FILE_BASE64`,
no shrunk `{ENV}_BUILD_ENV_BASE64` secret either — CI's only remaining
env-shaped secret is `{ENV}_KUBECONFIG` (+ registry/VPN creds). Both `laravel-config`
and Vite still get every public value they need; only runtime credentials
are gone from CI, entirely.

**Commands shipped, with different names/shapes than the original design:**

| Shipped | Not built (superseded) | Why |
| --- | --- | --- |
| `dotenv:push [env] [--app=]` | `secrets:push` | `secrets:*` was already the OpenBao-lifecycle namespace (init/seal/wire/…); `dotenv:*` already owned local `.env` ops (`dotenv`, `dotenv:audit`) |
| `dotenv:pull [env] [--app=]` | `secrets:pull` / `dotenv:pull` (plan used both names) | same reasoning, made consistent with `dotenv:push` |
| `dotenv --strict` (new flag on the **existing** `dotenv` command) | `dotenv:diff` | the existing `dotenv` command already did the file-vs-cluster comparison almost exactly as specced; a new command would have duplicated it line for line. `--strict` just adds the exit-1-on-drift behavior, with Plex/OpenBao-rotated keys excluded from the failure count |
| `secrets:grant` / `secrets:revoke` — per-app, per-environment, `developer`/`viewer` only | the plan's `--only=`/`--except=` multi-env, 3-tier `developer`/`viewer`/`admin` design | simplified to match the existing `sso:grant`/`sso:revoke` single-environment-positional convention; dropped the "admin, all envs for one app" tier — redundant with the existing global `openbao-admin` tier |

**Deliberately NOT built** (present in the plan's "Onboarding" / "RBAC" /
"Multi-Project Scoping" sections below, judged out of scope or already solved):
- App-first OpenBao path restructuring (`secret/data/{app}/{environment}/...`). Shipped instead: `secret/data/{environment}/{app}/...` — the app segment is composed locally inside `dotenv:push`'s existing `pushClusterSecret()` call, zero changes to that trait method's signature or its ~40 other call sites.
- `larakube clone`/`init` auto-prompting to run `secrets:pull` — not built; run it manually.
- Per-tenant Zitadel dynamic role creation was **not** a new subsystem: `secrets:grant` mints a role key (`secrets-{app}-{environment}-{role}`) on the *existing* `LaraKube RBAC` Zitadel project via the *existing* `zitadelEnsureProjectRole()` primitive — same project `sso:grant`'s fixed `openbao-admin/operator/auditor` tiers already live on.

**Drive-by fix (approved mid-implementation, unrelated to CI secrets):** the
`ExternalSecret` CRD `syncOpenBaoToNamespace()` wrote (`eso-sync.blade.php`)
was structurally broken — it used `dataFrom.extract.key: {environment}`,
expecting one combined KV document at `secret/data/{environment}`, but
`pushClusterSecret()` actually writes one KV entry **per key** at
`secret/data/{environment}/{key}`. That extract could never find real data.
Replaced with explicit per-key `data:` entries, and added an optional
`$prefix` param (default `null`, zero behavior change for existing callers)
so a sync can be scoped to one app's keys instead of pulling every flat key
under an environment.

**`sso:revoke`'s incident sweep already covers `secrets:grant` grants** — no
second revoke system, no blind spot. `ClusterTool::forGrantableRoleKey()`
routes any `secrets-`-prefixed role key to the RBAC project by prefix match
(app names are arbitrary, so they can't be enumerated in `SECRETS::rbacRoles()`
statically). One command (`sso:revoke`) can still wipe a compromised account's
access completely, including per-app grants.

**Files touched:** `app/Commands/DotenvPushCommand.php`, `DotenvPullCommand.php`
(new), `app/Commands/Secrets/SecretsGrantCommand.php`, `SecretsRevokeCommand.php`
(new), `app/Traits/InteractsWithAppSecretGrants.php` (new), `app/Commands/DotenvCommand.php`
(`--strict`), `app/Data/ConfigData.php` (`getPlexManagedKeys()`), `app/Enums/ClusterTool.php`
(`forGrantableRoleKey()` prefix match), `app/Traits/ConfiguresCloudEnvironment.php`
(`buildPublicEnvScript()`, `uploadGhaSecrets()`/`uploadGitlabVariables()`/`generateGitlabPipeline()`
rewritten), `app/Traits/ReadsEnvSources.php` (`isSecretKey()` shared), `app/Traits/SyncsClusterSecrets.php`
(`readOpenBaoKeys()`, `$prefix` support), `resources/views/k8s/secrets/eso-sync.blade.php`,
`resources/views/k8s/cloud-pilot-deploy.blade.php`, `cloud-pilot-deploy-gitlab.blade.php`,
`app/Commands/Pipeline/PipelineTestCommand.php`, `app/Commands/Plex/PlexJoinCommand.php` (comment only).

**Not yet verified against a real cluster** — every test fakes `Process`/`Http`.
See `plans/testing-checklist.md`'s "Secrets Out of CI" section for the manual
walkthrough before this ships in a release.

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

### ⚖️ Precedence & Collision Resolution Rules

If a developer runs `larakube secrets:push` containing a local `DB_PASSWORD` or `DB_USERNAME`, LaraKube enforces strict ownership precedence:

```
  ┌──────────────────────────────────────────────────────────────────┐
  │                    SECRET PRECEDENCE ORDER                       │
  │                                                                  │
  │  1. OpenBao Static DB Role (Highest Priority - Auto-Rotated)     │
  │     OpenBao engine owns DB_PASSWORD & DB_USERNAME.               │
  │                                                                  │
  │  2. OpenBao KV Store / K8s Secret (Pushed via secrets:push)       │
  │     (APP_KEY, AWS_SECRET_ACCESS_KEY, REVERB_APP_SECRET, etc.)    │
  │                                                                  │
  │  3. Local .env.{environment} File (Developer input source)       │
  └──────────────────────────────────────────────────────────────────┘
```

- **Static Role Protection**: `secrets:push` queries `SyncsClusterSecrets::staticRoleExists($roleName)` and `ConfigData::isPlexBacked()`. If a static role exists for `DB_PASSWORD`, `secrets:push` **automatically skips overwriting `DB_PASSWORD`** and surfaces a console notice:
  `"⚠️ DB_PASSWORD is managed by OpenBao static role 'app-db-role' — local .env value excluded from push."`
- **Self-Healing Reconciliation**: If a manual KV edit somehow alters `DB_PASSWORD`, OpenBao's static role reconciliation engine (and `larakube plex:rotate`) immediately rewrites the active rotated password back into `secret/data/{app}/{environment}/DB_PASSWORD`, guaranteeing that database connections never break!

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

#### Interactive Role Selection (`Laravel\Prompts\select`):
If `--role` is omitted in an interactive terminal session, LaraKube renders an interactive **Laravel Prompts** `select()` menu defaulting to `developer`:

```php
$role = $this->option('role') ?? select(
    label: 'Select the OpenBao RBAC role for this user',
    options: [
        'developer' => 'Developer (Read-Write: create, read, update, patch secrets)',
        'viewer'    => 'Viewer (Read-Only: read & pull secrets into local .env)',
        'admin'     => 'Admin (Full Access: read, write, delete & rotate credentials)',
    ],
    default: 'developer'
);
```

In non-interactive CI environments (`--no-interaction`), it defaults to `'developer'` automatically.

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

> ✅ All done 2026-08-04. Names/shapes changed from the original list below —
> see "✅ What actually shipped" at the top of this document for the actual
> commands and why. This list is kept as-written for history.

- [x] ~~Create `app/Commands/DotenvPushCommand.php` (`larakube dotenv:push [env]`)~~ — shipped as designed
  - Parses `.env.{env}`
  - Filters secret keys using `getSecretEnvironmentVariables()` **plus** a name-heuristic fallback (`PASSWORD`/`SECRET`/`KEY`/`TOKEN` substring) — `APP_KEY` and third-party API keys aren't in any enum's secret list at all, so the enum set alone silently missed them
  - If OpenBao present: calls `pushClusterSecret()`, app-scoped (`"{app}/{key}"`)
  - If OpenBao absent: delegates to the existing `InteractsWithRemoteDeploy::syncRemoteEnv()` (already did per-key `laravel-config`/`laravel-secrets` writes — this task turned out to be exposing existing plumbing as a standalone command, not writing it from scratch)
  - Actively excludes (not just warns about) Plex/OpenBao-managed keys from what gets pushed
- [x] ~~Create `app/Commands/DotenvDiffCommand.php`~~ — **not built**; shipped as `dotenv --strict` on the existing `dotenv` command instead (see rationale above). Exit-1-on-drift, values never printed by default (existing `dotenv` behavior), Plex/OpenBao-rotated keys excluded from the failure count via new `ConfigData::getPlexManagedKeys()`.
- [x] Update `app/Traits/ConfiguresCloudEnvironment.php`:
  - ~~Modify `uploadGhaSecrets()` to upload `{$ENV}_BUILD_ENV_BASE64`~~ — **went further**: no env-blob secret upload at all (see zero-blob design above). `uploadGhaSecrets()`/`uploadGitlabVariables()` now only handle registry creds + the scoped kubeconfig; `buildPublicEnvScript()` renders public vars as literal workflow text instead.
- [x] Update GHA Blade template (`resources/views/k8s/cloud-pilot-deploy.blade.php`) **and its GitLab twin** (not listed here originally, but Phase 1's own text called for both):
  - ~~Consume `{$ENV}_BUILD_ENV_BASE64` for `docker build`~~ — consumes `$publicEnvScript` (literal, not a secret) instead
  - Removed `kubectl create secret generic laravel-secrets --from-env-file=.env` from both templates
  - Added: a preflight step in both templates that fails the job with a `dotenv:push`-naming fix-it message if `laravel-secrets` is missing (not in the original checklist, but required by "Definition of done" below)
- [x] Create Pest unit and feature tests:
  - `tests/Feature/DotenvPushCommandTest.php`, `DotenvPullCommandTest.php` (pull wasn't in the original checklist at all — added once it became clear it was genuinely new, not a duplicate)
  - ~~`tests/Feature/DotenvDiffCommandTest.php`~~ — n/a, folded into `tests/Feature/DotenvCommandTest.php`'s `--strict` cases
  - Plus `SecretsGrantCommandTest.php`, `SecretsRevokeCommandTest.php`, an `sso:revoke` case proving it discovers/revokes a `secrets:grant`-issued key, and `GhaWorkflowTest.php` updates for the zero-blob design
- [x] Format with `./php vendor/bin/pint` and verify PHPStan (0 errors) — both clean, full suite 1333 passing

---

## ✅ Definition of done

- [x] No credential reported by `getSecretEnvironmentVariables()` — or matching
  the `PASSWORD`/`SECRET`/`KEY`/`TOKEN` name heuristic — is stored in any CI
  provider secret. (Stronger than specced: **no env-derived secret of any
  kind** is stored in CI at all, public or otherwise.)
- [x] `larakube dotenv:push {env}` creates/updates `laravel-secrets`, and the
  generated CI workflow (not `cloud:deploy` — see note below) refuses to
  proceed when it is absent.
- [x] `larakube dotenv --strict {env}` (not `dotenv:diff`) reports drift
  without printing values, and exits non-zero when the file and the cluster
  disagree.
- [x] The workflow templates no longer create the runtime Secret from a file.
- [x] Existing single-node / no-backend installs keep working with no extra
  components deployed, and never encounter the word "OpenBao".

**Note on the `cloud:deploy` line above:** research during implementation
found `cloud:deploy`'s own CLI path (`applyScopedDeploy()` → `syncRemoteEnv()`)
already self-creates `laravel-secrets` from the local `.env` on every run —
it was never actually exposed to the "missing Secret" ordering hazard the
plan worried about. The **generated CI workflow** was the one place that
hazard was real (once it stopped creating the Secret itself), so the preflight
check lives there instead.

**Known follow-up, not fixed as part of this plan:** `cloud:deploy`'s
`syncRemoteEnv()` is not Plex/OpenBao-aware — unlike `dotenv:push`, it has no
`getPlexManagedKeys()` exclusion. In the normal case this is harmless:
`PlexJoinCommand` already omits `DB_PASSWORD` from the local file entirely
once OpenBao owns it, and `kubectl apply` of a Secret manifest never deletes
keys absent from the new manifest, so the OpenBao/ESO-managed value survives
untouched. The gap only bites if a `DB_PASSWORD`-shaped key is *manually*
present in the local file for a Plex-backed environment (stale, or added
before joining Plex) — then a manual `cloud:deploy` would overwrite the live,
rotated value with that stale one. Narrow blast radius (manual `cloud:deploy`
only, not CI deploys, and only with a manually-reintroduced key), left as
out of scope for this plan.
