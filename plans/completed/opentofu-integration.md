# OpenTofu Integration — `cloud:create`

Status: **v1 implemented** (untested against live DO) · Initial target: **DigitalOcean** (VPS droplet + DOKS managed)

## Implementation status (2026-06-28)

Shipped, lint + PHPStan clean, 484 tests pass (11 new):
- `app/Traits/ProvisionsK3sNode.php` — extracted from `CloudProvisionCommand` (now shares it); added `waitForSsh()` + VPS Traefik-already-installed guard + `provisionK3sNode()` orchestrator.
- `app/Data/StackData.php` + `GlobalConfigData` — DO token, global stack registry (put/find/remove), per-stack PBKDF2 passphrase.
- `app/Traits/InteractsWithOpenTofu.php` — native-first resolution (`tofu`→`terraform`), `offerTofuInstall` (brew / install-opentofu.sh), `-chdir` runner, `TF_ENCRYPTION` + `TF_VAR_do_token` injection, workdir under `~/.larakube/tofu/<stack>/`.
- `resources/views/tofu/do-vps.blade.php` + `do-doks.blade.php`.
- `app/Commands/Cloud/CloudCreateCommand.php` (`cloud:create`) + `CloudDestroyCommand.php` (`cloud:destroy`).
- Tests: `tests/Feature/OpenTofuStackTest.php`, `tests/Feature/TofuTemplateTest.php`.

**Not yet done:** real `tofu apply` against a live DO account (needs a token + spend); `cloud:stacks` listing command; DOKS managed-hardening beyond the cloud:init:doks handoff (kube-API IP restriction + default-deny NetworkPolicies are still a printed follow-up, not automated).


## 1. Goal & scope

Add infrastructure *provisioning* to LaraKube. Today the cloud commands assume infra
already exists:

- `cloud:init` (`CloudProvisionCommand`) takes an **existing IP** and installs k3s + hardens it.
- `cloud:init:doks` takes an **existing DOKS cluster** and layers Traefik on it.

OpenTofu fills the gap *before* those — actually creating the droplet or DOKS cluster —
then **hands off to the existing flows** instead of reimplementing them.

```
cloud:create vps   ──(tofu apply)──> raw droplet  ──> existing k3s + hardening flow
cloud:create doks  ──(tofu apply)──> DOKS cluster ──> cloud:init:doks + managed hardening
cloud:destroy      ──(tofu destroy)──> tears down infra + offers context cleanup
```

`cloud:nuke` (wipes app resources, leaves infra running) and `cloud:destroy` (removes the
infra itself) are complementary, not overlapping.

## 2. Design decisions (resolved with maintainer)

| Topic | Decision |
|---|---|
| Tofu runtime | **Native host binary, like `kubectl`** (single static Go binary — trivial to install). Detect `tofu`, else `terraform` (drop-in fork; same HCL runs on either). If neither present, **offer auto-install** (§2b). Docker is only an optional last-resort, not the default — Tofu is *stateful* (state + provider-plugin cache), so containerizing it re-downloads providers each run and needs volume mounts for no real gain. (This reverses the earlier `gh`-style Docker-first lean; `gh` is stateless, Tofu isn't.) |
| Stack model | A **stack** = one droplet OR one cluster, **decoupled from environments** (N environments → 1 stack). Globally identified + registered, NOT owned by a single repo — so multiple projects can share one VPS/cluster. See §2a. |
| State + HCL location | **Global**, per stack: `~/.larakube/tofu/<stack>/`. (Reverses the earlier "commit to `.infrastructure/tofu/`" idea — committed-per-repo can't model infra shared across projects. Committed HCL becomes an optional export for review.) |
| State (OpenTofu) | **Encrypted at rest** via native state encryption (PBKDF2 passphrase, no KMS — stays cloud-agnostic). Protects the `~/.larakube` copy + backups. |
| State (Terraform fallback) | No native encryption → plaintext in `~/.larakube/tofu/<stack>/` (already outside any repo). |
| Encryption key | Random passphrase generated once, stored in global config `~/.larakube` (machine-local). Supplied at runtime via the `TF_ENCRYPTION` env var, never written into HCL. Caveat: lose the passphrase → state unrecoverable (acceptable: single server, re-import/recreate). |
| Concurrency/locking | **Not needed** — single operator runs `cloud:create`. Why global local state is fine without a remote backend. |
| Team state (later) | If ever multi-operator: optional **generic S3-compatible backend** (bring-your-own bucket: AWS S3 / R2 / MinIO / Backblaze / DO Spaces). Out of scope for v1. |
| App secrets (.env / kubeconfig) | **Not Tofu's job.** Stay in GitHub Secrets via the existing `cloud:configure --only=ci` flow. Putting them in Tofu would write them into state in plaintext. Tofu = infra only. |
| Provisioning CI | **None in v1** — `cloud:create` from the CLI covers it (infra is rare + high-blast-radius; teams gate it). Optional later: a manual `workflow_dispatch` provisioning workflow with the DO token as a GH secret. Never on the push-triggered deploy path. |
| Secrets | DO token flows via `TF_VAR_do_token` + global config — never written into HCL. |
| VPS resources (v1) | Droplet + SSH key + DO Cloud Firewall (from a shared, provider-agnostic firewall policy). |
| Hardening | In-VM hardening (UFW/fail2ban/key-only SSH) is already cloud-agnostic bash-over-SSH; reused as the universal layer. Cloud-edge firewall is the only provider-specific piece. |

## 2a. Stack model — infra ↔ environments (multi-project / multi-env)

The deploy unit in LaraKube is the **environment** (`env` → `<app>-<env>` namespace + `.env.<env>`
+ context + hosts). Infra is **not** 1:1 with environments, so model them separately:

- **Environment** — logical target (namespace + context + hosts). Many per project.
- **Stack** — one droplet or one cluster. Provisioned by Tofu. Globally registered.
- **Relationship: N environments → 1 stack.** Namespace isolation makes co-tenancy safe.

**Global stack registry** (in `~/.larakube` global config): per stack records `name`, `provider`
(do), `kind` (vps/doks), `region`, resulting `context`, `createdAt`. Lets `cloud:create` /
`cloud:destroy` / a future `cloud:stacks` enumerate and reuse stacks across projects.

**`cloud:create <env>` flow:**
```
New stack, or attach to an existing one? (from the global registry)
  • New:    name + region + size → tofu apply → context
  • Attach: pick existing stack  → reuse its context (no apply)
→ record context on this env's CloudData (recordManagedTarget); namespace = <app>-<env>
```

Covers all the scenarios:
- **Multiple projects** — any project's env attaches to any registered stack (global registry).
- **Each env runs `cloud:create`** — choose *New* each time → N droplets.
- **Two envs on one VPS/cluster** — both *Attach* to one stack → separate namespaces.

**Co-tenancy mechanics (must get right):**
- **Traefik once per cluster.** DOKS path already guards via `traefikInstalled()`; the **VPS path
  lacks this guard** and would re-deploy on a second env — add it.
- **Host-based routing** already separates apps by domain behind one Traefik.
- **Single-node resource warning** — prod + staging on one 1GB droplet is tight; warn, don't block.
- **Scoped per-namespace kubeconfigs** (existing) keep each env's CI isolated on shared infra.

## 2b. OpenTofu binary across WSL2 / Linux / macOS

**Native host binary, like `kubectl`** — not a containerized tool. OpenTofu ships as a single static
Go binary, and being *stateful* (state + provider-plugin cache) it fits the host-install model far
better than the Docker-wrapped `gh` pattern. Treat it as a host prerequisite (à la
`CheckPrerequisites`): detect it, and if missing **offer auto-install** (never silently force):

- **macOS** — `brew install opentofu` when Homebrew is present.
- **Linux / WSL2** — official `install-opentofu.sh` (deb/rpm/standalone); needs sudo → prompt first.
- **WSL2** — no special case; it's the Linux path (`DetectsWsl::isWsl()` available if ever needed).
- Resolution order at run time: native `tofu` → `terraform` → (optional last-resort) Docker.
- Docker fallback is **optional / may be cut from v1** — the binary is easy enough that requiring it
  (kubectl-style) keeps the code simpler and avoids the provider-cache re-download problem.

## 3. Components to build

### 3.1 Commands
- `cloud:create {env?} {target?}` — provision a stack (`vps` | `doks`) or attach `env` to an existing one. Stack fork per §2a; mirrors `cloud:init`'s target-select UX.
- `cloud:destroy {stack?}` — `tofu destroy` for a registered stack + optional `context:remove` (reuse pattern from `cloud:nuke`). Warn if envs are still bound to it.
- `cloud:stacks` (optional) — list the global stack registry (name / kind / region / context / bound envs).

### 3.2 New trait `InteractsWithOpenTofu`
Sibling of `InstallsK3s` / `InteractsWithServerHardening`.
- `tofuCommand()` — resolve native `tofu` → `terraform` (host binaries, kubectl-style); Docker only as an optional last resort. Plus `ensureTofu()` / `offerTofuInstall()` (brew on macOS / `install-opentofu.sh` on Linux+WSL2) per §2b.
- `tofuInit() / tofuPlan() / tofuApply() / tofuDestroy() / tofuOutput($key)` — thin `passthru`/`exec` wrappers; inject `TF_ENCRYPTION` (OpenTofu) + `TF_VAR_do_token`.
- `tofuWorkdir($stack)` — resolves the **global** `~/.larakube/tofu/<stack>/` and writes rendered HCL there.
- Stack registry helpers: `registerStack() / findStack() / listStacks() / forgetStack()` over the global config.

### 3.3 Refactor: extract `ProvisionsK3sNode` trait
Move the protected methods currently inside `CloudProvisionCommand` (`installK3s`,
`createLaraKubeUser`, `hardenServer`, `lockDownRootLogin`, `syncKubeconfig`,
`deployTraefik`) into a shared trait so **both `cloud:init` and `cloud:create` drive one
code path** — no drift. Add an **SSH-readiness poll** (fresh droplets take ~30–60s before
sshd answers) before the handoff. **Add a Traefik-already-installed guard to the VPS path**
(the DOKS path has one via `traefikInstalled()`; the VPS path re-deploys today) so a second
env attaching to the same VPS doesn't clobber Traefik — see §2a co-tenancy.

### 3.4 Tofu module templates (Blade-rendered, like `resources/views/k8s/*`)
New dir `resources/views/tofu/`:
- `provider.tf.blade.php` / `versions.tf` — pinned `digitalocean` provider.
- `encryption.tf.blade.php` — OpenTofu `encryption` block (PBKDF2) when the runtime is OpenTofu; omitted for Terraform. State stays in the global `~/.larakube/tofu/<stack>/` workdir (no remote backend in v1).
- `do-droplet.tf.blade.php` — droplet + `digitalocean_ssh_key`; outputs `ip`, `id`.
- `do-firewall.tf.blade.php` — DO Cloud Firewall rendered from the shared ruleset.
- `do-doks.tf.blade.php` — `digitalocean_kubernetes_cluster` (node pool, version, region); outputs kubeconfig + cluster id.

### 3.5 Provider-agnostic abstraction (forward-looking)
- **Compute module contract:** every compute module outputs `{ ip, id }`. `do-droplet` now; `aws-ec2` / `gce-instance` / `azure-vm` later satisfy the same contract — orchestration unchanged.
- **Firewall policy → native renderer:** one shared ruleset (22 / 80 / 443 open; **6443 restricted to admin IP**) rendered into each provider's native resource (DO Firewall now; AWS SG / GCP firewall / Azure NSG later).

### 3.6 Config / secrets
- `GlobalConfigData`: add `getDoToken/setDoToken` (same shape as the AI keys). Passed as `TF_VAR_do_token`.
- `GlobalConfigData`: add the **stack registry** (`getStacks/addStack/removeStack`) + the per-stack Tofu **encryption passphrase**.
- Env↔stack binding recorded on the project's `CloudData` (context) via existing `recordManagedTarget`.
- DOKS hardening reuses existing `InteractsWithScopedRbac` / `InteractsWithTeammateRbac`.

## 4. Requirement coverage

**A. Creation (VPS)** — `tofu apply` creates droplet, registers SSH key, attaches DO Firewall,
reads back IP via `tofu output`.

**B. Hardening + k3s (VPS)** — reuse `ProvisionsK3sNode`: k3s install, `larakube` user,
UFW/fail2ban/key-only SSH, disable root login, kubeconfig sync, Traefik. No reimplementation.

**C. Hardening (managed DOKS)** —
- DO Cloud Firewall + LB `trusted_sources` to restrict exposure (Tofu-managed).
- **Restrict kube-API to admin IP** — closes the "6443 open to the internet" gap the VPS path warns about.
- Default-deny `NetworkPolicy` baseline per namespace + Traefik via `cloud:init:doks` handoff.
- Optional least-privilege RBAC via existing traits.

**Handoff to the existing deploy flow (both paths).** OpenTofu stops at infra. The kubeconfig
then feeds the *unchanged* `cloud:configure --only=ci` flow:
- VPS: kubeconfig comes from `syncKubeconfig()` (k3s handoff).
- DOKS: kubeconfig comes from `tofu output` → fed into the same `mintScopedKubeconfig()`.
- Either way `.env.{env}` → `{ENV}_ENV_FILE_BASE64` and the scoped `{ENV}_KUBECONFIG` upload to
  GitHub Secrets exactly as today. App secrets never enter Tofu state.

## 5. Safety & idempotency
- `tofu plan` detects existing infra; reruns are safe (mirrors the DOKS `traefikInstalled()` short-circuit).
- SSH-readiness + "already provisioned" guards before handoff.
- `cloud:destroy` confirms (reuse `cloud:nuke`'s typed-name confirmation), then `tofu destroy` + optional context removal. **Warn if any environment is still bound** to the stack (registry lookup).
- State + HCL live in the **global** `~/.larakube/tofu/<stack>/` — outside any repo, so no project `.gitignore` changes are needed for state.
- Encryption preflight: OpenTofu → ensure the per-stack passphrase exists in `~/.larakube` (generate on first run); Terraform → warn state is plaintext-at-rest in `~/.larakube`.

## 6. Rollout order
1. `InteractsWithOpenTofu` (runtime resolution + optional install + wrappers + encryption setup + stack-registry helpers over `~/.larakube`).
2. Extract `ProvisionsK3sNode` from `CloudProvisionCommand`; wire `cloud:init` to it (no behavior change); add the VPS Traefik-already-installed guard.
3. DO token + stack registry in `GlobalConfigData`.
4. Tofu templates: provider + encryption + droplet + firewall.
5. `cloud:create vps` end-to-end — new-or-attach fork, apply → SSH poll → handoff → bind env.
6. DOKS template + `cloud:create doks` + managed hardening.
7. `cloud:destroy` (registry-aware, bound-env warning) + optional `cloud:stacks`.
8. Docs + tests (incl. multi-env-on-one-stack co-tenancy).

## 7. Open follow-ups (post-v1)
- **Committable IaC (wanted).** Export the per-stack HCL into the project repo (e.g. `.infrastructure/tofu/<stack>/`) so infra is version-controlled + reviewable alongside the app — while state stays global/encrypted. Pairs naturally with the S3-compatible remote backend below for true team IaC. Design the global stack workdir now so this is an additive export later, not a migration.
- Generic S3-compatible remote state backend for teams (bring-your-own bucket).
- Optional gated `workflow_dispatch` provisioning workflow (DO token as GH secret).
- Multi-node k3s (HA) — out of scope for v1 (single-node, consistent with current VPS flow).
- Other providers (AWS/GCP/Azure) — module contract is designed for them; not implemented in v1.
