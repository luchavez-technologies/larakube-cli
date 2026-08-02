# Plan: stop shipping `.env.{env}` to the CI provider — deliver runtime secrets to the cluster

## 🎯 Objective

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

## 📐 Settled: `.env.{env}` is authoritative

The local env file is the **source of truth**; the cluster and any secrets
backend are downstream copies. This is a deliberate choice, not an interim one —
it keeps the mental model the same whether or not a backend exists.

It also decides the command naming. **`dotenv:*`, not `secrets:*`** — most
installs will never run OpenBao, and a `secrets:` namespace implies a vault the
user doesn't have. `dotenv:` names the thing they actually edit.

| Command                   | Does                                                        |
| ------------------------- | ----------------------------------------------------------- |
| `dotenv:push {env}`       | `.env.{env}` → cluster Secret (and backend, when present)   |
| `dotenv:pull {env}`       | cluster/backend → `.env.{env}`, for recovery and onboarding |
| `dotenv:diff {env}`       | drift report, read-only, exits non-zero when they disagree  |

`dotenv:diff` is the one that earns its keep: once the file is authoritative,
the failure mode is silent divergence — someone edits a Secret with `kubectl`,
or a backend rotates a value, and the file no longer describes reality. It must
compare **keys and value-equality**, never printing values — only
`same / differs / missing here / missing there` per key. It's also the natural
pre-flight check for `cloud:deploy`.

## 🔧 Phase 1 — `larakube dotenv:push {env}`

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

## ❓ Open decisions (needed before implementation)

1. **How `heal` and `dotenv:pull` coexist.** `.env.{env}` being authoritative
   settles the direction of travel, but heal already rewrites those files from
   the blueprint — it syncs every cloud env file
   (`GeneratesProjectInfrastructure.php:930`) and seeds a missing one by copying
   local `.env` (`syncEnvFile()`, ~line 180), which is how local values like a
   `*.test` host leak into a cloud env file. So three writers exist: the user,
   heal, and `dotenv:pull`. Decide which keys each may touch — the natural split
   is that heal owns derived/architectural keys (`APP_URL`, `VITE_*`, hosts,
   ports) and `dotenv:pull` owns credentials, but that boundary should be
   explicit rather than emergent.
2. **Cross-cluster placement.** OpenBao would presumably live in
   `larakube-shared`, while app environments run on their own clusters. ESO can
   reach across, but that is a trust boundary worth choosing deliberately rather
   than inheriting.
3. **Unseal-key custody.** The real operational cost of OpenBao. Auto-unseal
   needs a KMS; manual unseal means an environment breaks on every pod restart.

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
