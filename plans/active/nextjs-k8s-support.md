# Plan: Finish Next.js support — Node manifest pipeline, distributed cache, Prisma

Status: Phases 1–3 built & in the working tree; full suite green (2131 passed).
Created 2026-09-05. (Phase 3b — Plex/`--no-plex` DB + secret + migrate init —
was built in a concurrent session; Phases 1, 2, 3a here.)

## ▶ RESUME HERE — remaining TODOs (nothing below is cluster-verified yet)

1. **End-to-end deploy verification (the big one).** Everything so far is proven
   only by container builds + unit tests — no real cluster run. On a real box:
   `nextjs:new next-demo` → `larakube up` (dev server + HMR) → `larakube preview:up`
   (standalone image + Redis pod) → `larakube env production` + deploy → confirm:
   the `prisma-migrate` init container exits 0 against the provisioned DB, the app
   connects (DATABASE_URL/REDIS_URL from the Secret), `/api/health` is green, and
   the Redis ISR cache is shared across replicas. Test BOTH Plex (default) and
   `--no-plex` (self-hosted DB + Redis pods).
2. **Prisma migrate init container robustness.** It runs
   `npx --yes prisma@6 migrate deploy` in the standalone image (schema COPY'd in
   Dockerfile.nextjs; CLI fetched at runtime) — needs network egress in the init
   container; verify in a locked-down cluster. A fresh scaffold has no migrations
   (no-op) — confirm that path doesn't error.
3. **Stale comment** in `resources/views/k8s/nextjs/deployment.blade.php` — the
   header still says "Phase 1 … no envFrom … no Prisma init container yet", now
   inaccurate (both are present). Trim it.
4. **Cloud ingress TLS** — the Next.js ingress is basic Traefik websecure; add
   cert-manager/ACME annotations before a real cloud deploy (fine for local/preview).
5. **Adonis/Nest fast-follow** — same gap (see Out of scope); generalize this
   pipeline into a shared "Node server" pipeline once Next.js is cluster-verified.

Pinned versions to re-verify on resume (both were `@latest` landmines this round):
`@fortedigital/nextjs-cache-handler ^3.3.0` (Next 16) and `prisma@6` (`@latest`
serves the 8.0 RC with a redesigned `init`).

## 🎯 Vision

`larakube nextjs:new` should produce a Next.js project that actually deploys and
runs — a Node **standalone** image, a correct Kubernetes workload, distributed
Redis ISR/RSC caching across pods, and Prisma migrations — with the same
end-to-end completeness the static-SPA frameworks (Astro/Vite/Docusaurus)
already have. Today it scaffolds the app and (after the recent fix) writes a
buildable `Dockerfile.nextjs`, but the Kubernetes layer is unimplemented, so
`next:new` cannot complete and `larakube up` cannot deploy it.

## 🧭 The core constraint

Next.js is a **Node server**, not PHP and not a static bundle. It is the third
workload class, and only the first two are wired:

| Class | Guarded? | Manifests | Image |
|-------|----------|-----------|-------|
| static SPA (`isStaticSpa()`) | yes (`generateK8sManifests` early-return → `generateStaticSiteManifests`) | self-contained dev-server + Caddy overlays | `Dockerfile.static` |
| PHP (Laravel/Statamic/WordPress) | default | `k8s.base.laravel` stack | `Dockerfile.php` |
| **Next.js (Node server)** | **NO — falls into the PHP stack** | **crashes** | `Dockerfile.nextjs` ✅ (done) |

## 🧱 Codebase grounding (read before implementing)

- **Done already** (this branch): `resources/views/docker/nextjs.blade.php`
  (official Vercel standalone multi-stage, `deploy` final stage), wired through
  `generateDockerfiles()`, `dockerfileFor()`, `buildImage()`, and `.dockerignore`.
  Real image build validated under rootless Podman.
- **Crash #2 (the current blocker):** `GeneratesProjectInfrastructure::generateK8sManifests()`
  only early-returns for `isStaticSpa()`. Next.js (frontend = null, not static)
  falls through to the `baseStubs` render of `k8s.base.laravel`, which
  `@include`s `k8s.base.deployment` — line 4 is `getServerVariation()->getPodName()`.
  Next.js has a **null** `serverVariation` → fatal. There are **15** unguarded
  `getServerVariation()->` sites across the base/overlay views it would render.
- **Orphaned templates:** `resources/views/k8s/nextjs/{deployment,ingress,migrate-init-container}.blade.php`
  exist but are referenced **nowhere** in `app/`. `deployment.blade.php` already
  targets `{name}:latest`, port 3000, `/api/health`, `envFrom` a
  `{name}-nextjs-config` ConfigMap + `{name}-nextjs-secrets` Secret, with a
  `prisma-migrate` init container running `npx prisma migrate deploy`.
- **Pattern to mirror:** `generateStaticSiteManifests()` — self-contained
  local + preview + per-cloud-env overlays, no shared `base/`, each rendered via
  a small `$render($stub, $view, $data)` closure and `writeManagedManifest()`.

## 🏗 Design — a third workload path

Add `generateNextjsManifests(ConfigData $config)`, mirroring the static one, and
guard it in `generateK8sManifests()`:

```php
if ($config->framework?->isStaticSpa()) { $this->generateStaticSiteManifests($config); return; }
if ($config->framework === AppFramework::NEXTJS) { $this->generateNextjsManifests($config); return; }
// … existing PHP/Laravel base stack …
```

`generateNextjsManifests()` produces, per environment:

- **ConfigMap** `{name}-nextjs-config`: `NODE_ENV`, `PORT=3000`, `HOSTNAME=0.0.0.0`,
  `NEXT_PUBLIC_*` (non-secret), `REDIS_URL` host (non-secret parts).
- **Secret** `{name}-nextjs-secrets`: `DATABASE_URL` (Prisma), Redis auth,
  `NEXT_PUBLIC_*` that are secret. Reuse the existing secret-sync path
  (`SyncsClusterSecrets`) and Plex/self-hosted provisioning already used by the
  other frameworks — do not invent a new secret mechanism.
- **Deployment** (wire the existing `k8s.nextjs.deployment`) + **Service** (new)
  + **Ingress** (wire `k8s.nextjs.ingress`).
- **Kustomizations**: base + `overlays/local` + `overlays/{cloudEnv}` (mirror the
  static kustomization stubs).

**Local runtime — DECIDED (A), 2026-09-05:** `larakube up` runs the Next.js dev
server (`npm run dev`, HMR) in a Node pod with source mounted, mirroring the
static frameworks' local overlay; `larakube up --preview` builds and runs the
standalone `Dockerfile.nextjs` image for production parity on demand. This keeps
Next.js consistent with the existing static local/preview split. Phase 1's local
overlay is therefore a dev-server workload, not the built image.

## 🚦 Phases

### Phase 1 — Node manifest pipeline (unblocks `next:new` + local deploy) ✅ DONE 2026-09-05
- ✅ `NEXTJS` guard + `generateNextjsManifests()` (self-contained overlays).
- ✅ Enum: Next.js dev-server port (3000) / flags (`-H 0.0.0.0 -p 3000`) / `dev` script.
- ✅ Local overlay reuses the generic `k8s.static.dev-server` (HMR, node_modules PVC).
- ✅ New views: `k8s/nextjs/service`, `k8s/nextjs/cloud-kustomization`,
  `k8s/nextjs/preview-kustomization`; rewrote `deployment` (parameterized,
  Phase-1 minimal — no envFrom, no Prisma init) + env-aware `ingress`.
- ✅ `preview:up` enabled for Next.js (`previewModeRefusal` + framework-aware
  `buildStaticPreviewImage` → `Dockerfile.nextjs --target deploy`).
- ✅ Regression test invokes the real `generateNextjsManifests`; full suite green.
- **Deferred within Phase 1:** cloud ingress TLS (cert-manager) hardening; no
  ConfigMap/Secret yet (arrive with Phases 2/3).
- **Exit met:** `next:new` completes; `larakube up` = dev server, `preview:up` =
  standalone image. No Prisma, no distributed cache yet.

### Phase 2 — Distributed Redis cache ✅ DONE 2026-09-05
- ✅ Swapped `@neshca` → **`@fortedigital/nextjs-cache-handler`** (`^3.3.0`,
  verified against Next `16.3.4`) using its `redis-strings` handler + graceful
  Redis-down fallback.
- ✅ Install the deps at scaffold (`installCacheDependencies` → npm install in a
  node container, npm-cache volume, chown-safe).
- ✅ `patchNextConfig` wires `cacheHandler: require.resolve('./cache-handler.mjs')`
  **production-only** (dev keeps Next's cache), + `cacheMaxMemorySize: 0`; dropped
  the removed `instrumentationHook`.
- ✅ `REDIS_URL` env + a per-namespace Redis pod (reused `k8s.redis.deployment`)
  in the **preview + cloud** overlays only — the dev server runs `next dev`, so
  its cacheHandler is off and it needs no Redis.
- ✅ **Build-validated end to end**: real `next build` (production, Redis absent
  → fallback) produced a standalone `server.js`. 70 tests green.
- **Limitation (documented):** Next 16 `cacheHandlers` (plural, `"use cache"`)
  isn't supported upstream yet; `cacheHandler` (ISR/route) is.

### Phase 3 — Prisma migrations + database provisioning (Plex / --no-plex) ✅ DONE 2026-09-05
Built and verified: `nextjs:new` provisions via Plex Commons by default (or a
self-hosted DB+Redis with `--no-plex`), scaffolds Prisma 6, constructs
`DATABASE_URL`/`REDIS_URL` into `.env` → a `{name}-nextjs-secrets` Secret →
`envFrom` on the deployment, and runs `prisma@6 migrate deploy` in an init
container. `kubectl kustomize` builds the preview overlay cleanly (Secret + app
+ Redis + Postgres + migrate init + image rewrite). Full suite green.

Original plan below (DECISION 2026-09-05): Next.js is on par with Laravel — mirror
`NewCommand` / `wordpress:new`. Default provisions the DB (and Redis) from **Plex Commons**
(`InteractsWithPlex::ensurePlexProvisionedForApp`); a **`--no-plex`** flag falls
back to self-hosted pods. This reconciles Phase 2b: the Redis pod I added is the
`--no-plex` path — under Plex, `REDIS_URL` comes from the Commons Redis (+ the
allocated logical index), not a per-app pod.

- **Command parity:** add `InteractsWithPlex` (+ `GathersInfrastructureConfig`)
  and the `--no-plex` option to `NextjsNewCommand`; call
  `ensurePlexProvisionedForApp($config)` unless `--no-plex`, wiring
  `environments[env]->plex` exactly as `wordpress:new` does.
- **Secret/env wiring (grounded recipe):** `InteractsWithPlex::commonsEnvValues($tenant, $password, $redisIndex, $services, …)`
  is public and framework-agnostic — it returns `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD`
  and `REDIS_HOST/REDIS_PORT/REDIS_DB`. Next.js/Prisma is the **first** framework
  to need URL form, so build:
  - `DATABASE_URL = {postgresql|mysql}://{DB_USERNAME}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/{DB_DATABASE}`
  - `REDIS_URL = redis://{REDIS_HOST}:{REDIS_PORT}/{REDIS_DB}`
  Write them into the project `.env` (merge helper at `InteractsWithPlex:350`),
  then generate a `{name}-nextjs-secrets` Secret from `.env` and `envFrom` it in
  the deployment (replacing Phase 1/2's inline env). `--no-plex`: a self-hosted DB
  pod (mirror the Redis pod) with the same URL shapes at in-cluster names.
  NOTE: no framework writes `DATABASE_URL` today — this is net-new; the PHP stacks
  use discrete `DB_*`. Reuse `commonsEnvValues` rather than re-deriving hosts.
- **Prisma scaffold:** ✅ built — `npm install prisma@6 @prisma/client@6` +
  `prisma@6 init --datasource-provider {postgresql|mysql}`, `prisma generate` in
  the Dockerfile via the pinned local binary. **PINNED to v6:** `prisma@latest`
  currently resolves to `8.0.0-rc.13`, whose `init` is redesigned (no
  `--datasource-provider`, no schema scaffold — it manages "agent skills" +
  prisma.config.ts). v6 is the current stable with the classic workflow; revisit
  when 8.x ships stable. (Caught by the build validation — the repo vendoring
  rule in action.)
- **Standalone + Prisma v7 tension:** standalone trims `node_modules` and omits
  `prisma.config.ts`, so the migrate step needs the full source tree — use a
  dedicated **migrate image/stage** for the init container, not `{name}:latest`.
- **Exit:** with Plex reachable, `prisma migrate deploy` runs green in the init
  container against the Commons DB; `--no-plex` does the same against the
  self-hosted pod.

## ⚠️ Risks / caveats
- **Version churn:** Next 16 cache API and Prisma 7/8 config moves are recent and
  active — re-verify package versions/APIs at build time; do not pin from memory.
- **Secret wiring:** `DATABASE_URL`/`REDIS_URL` must come from the same
  Plex/self-hosted provisioning the other frameworks use; a bespoke path risks
  drift and the "silently frozen secret" class of bug.
- **Standalone tree-shaking** repeatedly bites (Prisma CLI, cache handler,
  `public/`, `cache-handler.mjs`) — every runtime file needs an explicit COPY.
- **`getServerVariation()` null:** guarding Next.js out of the base stack is
  mandatory; do not attempt to null-thread the 15 PHP-view sites.

## ✅ Verification
- Manifest **render** tests per env (no null deref) — mirror the existing
  WordPress/Next.js Dockerfile-render regression tests.
- `kustomize build overlays/{local,production}` succeeds.
- `larakube up` on the local cluster: pod Ready, `/api/health` 200, page served.
- Phase 2: ISR value stable across 2 replicas.
- Phase 3: init container `migrate deploy` exits 0 against the provisioned DB.

## 🔭 Out of scope (tracked fast-follow)
- **Nest.js and AdonisJS** — both have the *identical* gap (orphaned
  `k8s/{nestjs,adonisjs}` views, no Node Dockerfile, null `serverVariation` →
  crash in the PHP stack, enum dev-server methods unset). Deferred until Next.js
  is fully closed; the efficient path is to generalize this Next.js pipeline into
  a shared "Node server" pipeline + one parameterized Node Dockerfile so both
  drop in as enum config. Adonis migrates via Lucid (`node ace migration:run`),
  Nest via TypeORM/Prisma — their own Phase-3 equivalents.
- Next 16 `"use cache"` / `cacheHandlers` (plural) — not yet supported upstream
  by the chosen handler.
