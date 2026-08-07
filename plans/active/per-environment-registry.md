# Plan: Per-environment container registry

**Status:** ✅ BUILT (v0.13.0) — verified 2026-08-08: `RegistryProvider` has GHCR/DOCKERHUB/GITLAB/GITEA and `cloud:configure {env} --only=registry` is the entry point.

## 🎯 Objective

Let each environment publish its image to a **different container registry** —
e.g. `local` builds locally and pushes nowhere, `staging` → GHCR, `production` →
Docker Hub or AWS ECR. Today the generated CI/CD pipeline is hardwired to GHCR;
this makes the registry a per-env choice that drives image references, the GitHub
Actions login/build/push steps, and the image-pull-secret strategy from one
source of truth.

This is the per-env realization of "managed-k8s Phase 6" (which sketched a single
top-level `registry` block). Per-env is the right unit because the CI workflow is
*already* generated per environment.

## 🔍 How it works today (all GHCR)

- **Image refs** in every app manifest are a placeholder: `image: {name}:latest`
  (web/horizon/queues/reverb/scheduler/ssr/local-node). Registry-agnostic by
  design — CI rewrites it at deploy time.
- **The workflow is per-env already.** `larakube cloud:configure gha` takes an
  environment and writes `.github/workflows/larakube-deploy-{env}.yml` from
  `resources/views/k8s/cloud-pilot-deploy.blade.php`.
- **But GHCR is hardcoded** in that template, in three places:
  1. `env:` block — `REGISTRY: ghcr.io`, `IMAGE_NAME: <github.repository>`.
  2. Login step — `docker/login-action` to `ghcr.io` using `${{ github.actor }}`
     + `${{ secrets.GITHUB_TOKEN }}`.
  3. Build-push tags + the deploy step's `sed` that swaps `{name}:latest` for
     `{registry}/{image}:{sha}`.
- A legacy `resources/views/k8s/github-actions-deploy.blade.php` does the same —
  audit whether it's still used and fold or delete it.

## 🧱 Design

### Schema — `environments[env].registry`

```json
"environments": {
  "local":      {},                                  // null → builds locally, no push
  "staging":    { "registry": { "provider": "ghcr" } },
  "production": { "registry": { "provider": "dockerhub", "image": "acme/app" } }
}
```

`RegistryData` (nested on `EnvironmentData`, like `cloud`):

- **`provider`** — `ghcr | dockerhub | ecr | gar | custom`.
- **`image`** — repo/path within the registry (e.g. `acme/app`, or an ECR repo
  name). Defaults sensibly per provider (GHCR → the GitHub repo).
- **`host`** — registry host for `ecr` (`<acct>.dkr.ecr.<region>.amazonaws.com`),
  `gar` (`<region>-docker.pkg.dev`), or `custom`. Derived/blank for ghcr/dockerhub.
- **`region`** — for ECR/GAR where the login action needs it.

**`local` has no registry** (null) — local images are built and side-loaded into
k3d, never pushed. Resolver `getRegistry($env)` returns null for local and for any
env without one (→ that env simply has no deploy workflow / push step).

Optional **project-level default** so common cases don't repeat: a top-level
`registry` an env inherits unless it sets its own. (Decide whether to include in
v1 or keep strictly per-env.)

### What the registry choice drives

1. **Image reference** — the `{registry-host}/{image}:{tag}` used in the CI build,
   push, and the deploy `sed`. Manifests keep the `{name}:latest` placeholder
   (no manifest change needed); CI substitutes per env.
2. **Workflow auth + push** — branched per provider in the generated workflow
   (see matrix). `cloud:configure gha` emits the right login step and required
   secret names.
3. **Image pull secret** — the registry decides the default pull-secret strategy,
   unifying with the existing per-env `imagePullSecret` / `omitImagePullSecret`
   knobs (managed-k8s Phase 1):
   - `ghcr` → `ghcr-login` secret (today's default)
   - `dockerhub` → a `dockerhub-login` secret
   - `ecr` → omit (node role / IRSA pulls) → `omitImagePullSecret`
   - `gar` → omit (Workload Identity) or a key secret
   - `custom` → a named secret
   `imagePullSecret`/`omitImagePullSecret` remain manual overrides on top.

### Provider matrix (CI login/push)

| provider   | login action | registry host | extra GH secrets |
|------------|--------------|---------------|------------------|
| `ghcr`     | `docker/login-action` (github.actor + `GITHUB_TOKEN`) | `ghcr.io` | none (built-in) |
| `dockerhub`| `docker/login-action` | `docker.io` | `DOCKERHUB_USERNAME`, `DOCKERHUB_TOKEN` |
| `ecr`      | `aws-actions/configure-aws-credentials` → `amazon-ecr-login` | `<acct>.dkr.ecr.<region>.amazonaws.com` | OIDC role **or** `AWS_ACCESS_KEY_ID`/`SECRET` |
| `gar`      | `google-github-actions/auth` → docker login | `<region>-docker.pkg.dev` | GCP WIF or SA key |
| `custom`   | `docker/login-action` | `host` | `<PREFIX>_USERNAME`, `<PREFIX>_TOKEN` |

### Command UX

- **`cloud:configure gha`** gains a registry step per env: pick provider, capture
  image/host/region, and push the provider's required secrets (it already pushes
  `{ENV}_KUBECONFIG` / `{ENV}_ENV_FILE_BASE64` via `gha:configure`). Then it
  generates the workflow with the matching login/push/sed for that provider.
- Default stays **GHCR** when an env declares no registry, so existing projects
  regenerate to identical workflows.

### Template changes

- `cloud-pilot-deploy.blade.php`: replace the hardcoded `env.REGISTRY`, the
  login step, the build-push tags, and the deploy `sed` with values derived from
  the env's registry (passed in via the `$gha`/registry context that
  `GhaConfigureCommand` already assembles). Branch the login step by provider.
- Reconcile/remove the legacy `github-actions-deploy.blade.php`.

## 🚦 Phases

1. **Schema + resolver** — `RegistryData`, `EnvironmentData.registry`,
   `getRegistry($env)` (local/none → null). No output change yet. Snapshot-stable.
2. **Pull-secret unification** — `getImagePullSecret($env)` defaults derived from
   the registry provider (GHCR→ghcr-login, ECR→omit, …); explicit knobs still win.
3. **Workflow generation** — branch `cloud-pilot-deploy` login/push/sed by
   provider; `cloud:configure gha` prompts for registry + pushes provider secrets.
   Default GHCR → byte-identical workflow for existing projects.
4. **Docs + legacy cleanup** — document per-env registry (Blueprint Anatomy +
   a CI/CD page), fold/remove `github-actions-deploy.blade.php`.

## ✅ Verification

- A blueprint with `staging: ghcr`, `production: dockerhub` generates two
  workflows whose login + push + sed target the right registry, with the right
  secret names.
- `local` (no registry) generates no deploy workflow / push step.
- An ECR production env omits the image pull secret (pulls via node/IRSA) and
  uses the ECR host in image refs.
- Existing GHCR-only projects regenerate to an unchanged workflow (regression).

## ⚠️ Risks / open questions

- **Secret bootstrapping.** Non-GHCR providers need credentials in GitHub secrets;
  `cloud:configure gha` must collect and push them (and document the AWS/GCP OIDC
  path, which is the better long-term auth than long-lived keys).
- **Project-level default vs strictly per-env** — include an inheritable default
  or keep it per-env only? (Per-env matches the rest of the schema; a default just
  avoids repetition.)
- **ECR repo existence.** ECR doesn't auto-create repos on push like GHCR/Docker
  Hub — may need a create-repo step or a documented prerequisite.
- **Image tag in manifests.** Keep the `{name}:latest` placeholder + CI `sed`, or
  move to a real `{host}/{image}` ref in the overlay? Placeholder keeps manifests
  registry-agnostic and local-friendly — prefer keeping it.
- **Ties into managed-k8s Phase 6** — this plan supersedes that sketch; update the
  managed-k8s plan to point here.
