# Handoff: DOKS deployment test

A self-contained brief so a fresh agent can pick up the DigitalOcean Kubernetes
(DOKS) validation **without prior conversation context**.

## TL;DR
- **Goal:** validate LaraKube end-to-end on a real DOKS cluster.
- **Start SIMPLE:** a **1-node** DOKS pool, `strategy: single-node`, a **public
  GHCR image**, **plain HTTP first**. Get one boring deploy green, then layer on
  TLS / scale. This sidesteps the multi-node storage blocker (below).
- All DOKS code is committed locally (v0.15.0 + post-tag commits), **not pushed**.
  The binary must be rebuilt before anything works.

## Conventions (READ FIRST)
- **HARD RULE (see MEMORY.md):** a specific project name must NEVER appear anywhere
  in this repo — code, docs, plans, or commit messages. Before EVERY commit, grep
  the staged files for that name (it's in MEMORY.md) and **read the result** —
  abort the commit if it matches.
- **The user runs builds.** After editing `laravel-k8s-cli/`, stop at the edit —
  the user runs `./php vendor/bin/pint && ./build` themselves.
- Verify with `./php vendor/bin/pest` and
  `./php vendor/bin/phpstan analyse --no-progress --memory-limit=2G` (use 2G — the
  default crashes a worker and prints a false "Found 1 error").
- Commit messages end with: `Co-Authored-By: Claude Haiku 4.5 <noreply@anthropic.com>`.
- Test apps live in `kube-examples/` (e.g. `larakube-vue-test`, `larakube-react-test`).

## What's built & DOKS-ready (committed; lint/phpstan/pest green)
- `cloud:provision:doks --context <ctx>` — installs Traefik with a Let's Encrypt
  **ACME (HTTP-01)** resolver + persistence; returns the LoadBalancer IP.
- **Managed identity:** `CloudData.context` (+ `provider`); `environmentContextOrCurrent`
  resolves a VPS `larakube-{ip}` OR a managed `context`.
- `cloud:deploy` registry path resolves the env context (managed-aware).
- **GHCR pull secret** is created via `kubectl --context` (no SSH) — works on
  managed clusters, any strategy.
- **Scoped-RBAC deploy:** admin creates the namespace, the app is applied as a
  namespace-locked `deployer` ServiceAccount, and the cluster-scoped `Namespace`
  doc is stripped from the scoped apply.
- `ManagedProvider` enum → default storageClass (doks → `do-block-storage`).

## Smoothest first run (do exactly this)
1. `./php vendor/bin/pint && ./build`
2. Create a **1-node** DOKS cluster in the DO UI. Get credentials:
   `doctl kubernetes cluster kubeconfig save <name>` **or** download the kubeconfig
   from the UI and `larakube context:import <file>`.
3. `larakube cloud:provision:doks --context <doks-context>` (Traefik+ACME, prints LB IP).
4. In a test project, set the env (`.larakube.json`):
   - `strategy: single-node` (1-node pool → avoids the multi-node storage blocker)
   - `ingress: traefik`
   - `hosts.web`: a domain you control (A-record → the LB IP)
   - `registry: { provider: ghcr }` — use a **public** GHCR image for run #1 (skips pull-secret)
   - `storageClass: do-block-storage`
   - Managed `context` is recorded when `cloud:deploy` first prompts (the
     "pick a context / new VPS" picker → pick the DOKS context → it asks provider).
     OR hand-edit `cloud: { context: "<doks-ctx>", provider: "doks" }`.
5. `larakube cloud:deploy production` → build+push, scoped apply, rollout. Expect a
   green rollout; app reachable on the LB IP over **HTTP**.
6. THEN TLS: add `ingressAnnotations` (`traefik.ingress.kubernetes.io/router.entrypoints: websecure`,
   `…/router.tls.certresolver: letsencrypt`), point DNS, redeploy.

## Known gotchas / what to watch
1. **Binary stale** until `./build`.
2. **MULTI-NODE STORAGE — the big one.** Do NOT use `multi-node-ha` for the first
   test. App pods (`web`/`horizon`/`queues`/`scheduler`/`reverb`) share a storage
   PVC; multi-node needs **RWX**, which `do-block-storage` can't do → `Pending`
   pods. Use 1-node + `single-node`. Full design + the **non-negotiable "no silent
   gotcha on upgrade" guard**: `plans/active/multi-node-storage.md`.
3. ~~**Managed-env config UX is rough:** `cloud:configure:base` is VPS-only~~ —
   **stale, already fixed independently of this plan.** `cloud:configure` (the
   `:base` sub-command was later merged into it, see
   `plans/active/paas-core-expansion.md` §7.2) already offers a kube-context
   picker (VPS **or** managed) via `promptCloudTarget()`, falling back to a
   manual IP/SSH prompt only when no contexts are found — no hand-edit needed
   for the managed case.
4. **Traefik ACME on DOKS is UNPROVEN** — get HTTP green first. The cert needs DNS
   resolving + port 80 reachable + the annotation present.
5. **Pull secret:** only GHCR is wired. Public image or GHCR for run #1; Docker Hub
   private needs more work.

## Backlog (plans/active/)
- `multi-node-storage.md` — RWX/externalize design + the no-gotcha upgrade guard.
  **HIGH priority; gates real multi-node.** Default = externalize (S3+Redis, e.g.
  via Plex); opt-in = in-cluster NFS so shared-folder apps upgrade unchanged.
- `arm-edge-deploy.md` — arch-aware deploy (Pi/ARM); the `linux/amd64` hardcode.
- `scoped-rbac-deploy.md`, `rbac-teammate-access.md`, `server-hardening.md` — mostly shipped.
- Docs follow-ups (docs repo): DOKS quickstart pass; "UI kubeconfig → `context:import`"
  note; "Team Access works on managed too" note; and a **VPS → multi-node upgrade
  guide** — write that AFTER the storage solution + guard exist (else it's all caveats).

## Repo state
- CLI on `main`; **v0.15.0 tagged**; post-tag commits up to `cb350a2` (local, unpushed).
- Docs repo: v0.15.0 + Security/Teams sections committed (local, unpushed).
- Push only when validated: CLI `git push && git push --tags`; docs `git push`.
