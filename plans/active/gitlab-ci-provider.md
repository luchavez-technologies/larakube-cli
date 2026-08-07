# Plan: GitLab CI/CD support (CI-provider abstraction)

**Status:** ✅ BUILT — verified 2026-08-08: GitLab CI generation lives in `InteractsWithPipelines` + `ConfiguresCloudEnvironment`, `RegistryProvider::GITLAB` is a case, and the entry point is now `cloud:configure {env} --only=ci` (the old `cloud:configure:gitlab` was folded in).

## 🎯 Objective

Let LaraKube generate a **GitLab CI/CD pipeline** (`.gitlab-ci.yml`) as an
alternative to GitHub Actions, so teams on GitLab (free CI/CD; many migrate off
GitHub) get the same build → push → `kubectl apply -k` deploy. Do it by
abstracting CI generation behind a provider, not by forking the whole pipeline.

GitLab is free and capable: CI/CD is built in (`.gitlab-ci.yml`), GitLab.com's
free tier includes a monthly pool of shared-runner minutes, and self-managed /
own-runner is effectively unlimited.

## 🔍 Today (GitHub Actions only)

- `cloud:configure gha` → `gha:configure` push secrets and render
  `resources/views/k8s/cloud-pilot-deploy.blade.php` into
  `.github/workflows/larakube-deploy-{env}.yml` (per-env already).
- The workflow: resolve secrets → set kube context → build assets → build+push
  image (GHCR) → `kubectl kustomize overlays/{env} | sed image | kubectl apply`.
- All of it assumes GitHub: `${{ secrets.* }}`, `github.actor/token/sha`,
  `docker/login-action`, GHCR.

## 🧱 Design

### Provider choice

`environments[env]` (or project-level) gains a CI provider: `ci: github | gitlab`
(default `github`). Could also live alongside the per-env `registry` block since
they're both CI concerns — decide during design (the two plans are siblings).

### What differs GitHub → GitLab

| Concern | GitHub Actions | GitLab CI |
|---|---|---|
| File | `.github/workflows/larakube-deploy-{env}.yml` | `.gitlab-ci.yml` (stages, or per-env jobs) |
| Secrets/vars | repo secrets (`${{ secrets.X }}`) | CI/CD variables (`$X`) |
| Image build | `docker/build-push-action` | `docker:dind` service, **or** kaniko/buildah (no privileged) |
| Registry login | `docker/login-action` | `docker login`; GitLab Container Registry is the natural default (`$CI_REGISTRY*`) |
| Identity | `github.sha`, `github.actor` | `$CI_COMMIT_SHA`, `$CI_REGISTRY_USER` |
| Deploy | `azure/k8s-set-context` + kubectl | `kubectl` image + `$KUBECONFIG` var |

### Approach

1. **Extract the shared shape.** The deploy logic (env file, kube context,
   build assets, build+push image, `kustomize | sed | apply`, rollout waits) is
   provider-independent. Pull it into a small spec the templates render from.
2. **Two templates.** Keep `cloud-pilot-deploy.blade.php` (GitHub) and add
   `gitlab-ci.blade.php`. Each renders the same spec in its own syntax.
3. **Command.** Either a generic `ci:configure` that branches by provider, or
   teach `cloud:configure gha` a sibling `cloud:configure gitlab`. The command
   sets the provider's variables/secrets and writes the right file.
4. **Registry pairing.** GitLab's built-in Container Registry (`$CI_REGISTRY`)
   becomes a first-class option in the per-env-registry plan
   ([per-environment-registry.md](./per-environment-registry.md)) — `provider:
   gitlab`. The two plans should land together or back-to-back.

## 🚦 Phases

1. **Spec extraction** — factor the deploy steps into a provider-neutral spec
   (no behavior change to the GitHub output; snapshot/golden-file stable).
2. **GitLab template** — `gitlab-ci.blade.php` rendering build→push→deploy with
   `docker:dind` or kaniko + GitLab Container Registry.
3. **Command + schema** — `ci` provider field; `cloud:configure gitlab` (vars +
   file). Default stays GitHub.
4. **Docs** — a "Deploy with GitLab CI" page; note runner requirements.

## ✅ Verification

- A `ci: gitlab` blueprint emits a valid `.gitlab-ci.yml` that builds, pushes to
  the chosen registry, and `kubectl apply -k`s the env's overlay.
- `ci: github` (default) output is unchanged (regression).
- Both reach the same cluster given the right kubeconfig variable/secret.

## ⚠️ Risks / open questions

- **Docker-in-Docker vs kaniko.** dind needs a privileged runner (often
  unavailable on shared/managed runners); kaniko/buildah is the rootless path.
  Pick a sensible default and document the runner requirement.
- **One file vs per-env.** GitHub gets one workflow file per env; GitLab uses a
  single `.gitlab-ci.yml` — model per-env as jobs/rules within it.
- **Self-managed vs GitLab.com** — variable scoping and registry host differ;
  keep the registry host configurable (pairs with the registry plan).
- **Scope creep.** Don't try to match every GitHub feature; target the
  build→push→deploy core that LaraKube actually generates.
