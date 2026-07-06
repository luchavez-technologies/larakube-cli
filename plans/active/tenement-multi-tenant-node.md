# speckit.plan: The Tenement — Multiple Projects on One Node

> **Resolved decisions (2026-05-30):**
> - **Name: CONFIRMED `Plex`** (user, 2026-05-30) — the feature *and* CLI verb are
>   **`plex`** (short for du/tri/multi-plex — one building, many units), replacing
>   "tenement" (kept only as this plan's filename/codename). So `larakube plex
>   init|join|status|leave`. The *shared-services bundle* is the **Commons**; each
>   app is a **Tenant**. Already used in the public docs (Scaling Journey).
> - **Node coverage: works on single-node AND multi-node.** The Commons is a
>   Postgres+Redis in `larakube-plex`; tenants reach it via `managed` + a host
>   pointing at its in-cluster address — node-count-agnostic. On multi-node the
>   in-cluster Commons is a single instance (shared-fate); graduate it to a
>   managed DB for HA via the *same* `managed` mechanism (different host only).
> - **Tier 0 is verified working today** — see the audit note in the Tier 0
>   section below; two-apps-one-server needs no new code, only a runbook.

## 🎯 Objective

Let a hobbyist run **two (or more) LaraKube projects on a single small VPS** (the $12/mo DigitalOcean 2GB tier) without each project needing its own server. The projects live in **separate GitHub repositories** and deploy to **separate namespaces** on the shared box.

There are two tiers here, and they are deliberately separated by effort:

- **Tier 0 — Two projects, one server, separate namespaces (the near-term goal).** Mostly works today. No new commands. This is what "keep it simple" means and what we should ship/document first.
- **Tier 1 — The Tenement (optional density tier, later).** Shared data services (the "Commons") so the apps don't each pay full RAM for their own Postgres/Redis. This is where new `tenement` commands come in. Intriguing, genuinely useful on 2GB, but NOT required to put two apps on one box — it's an optimization. **Hard requirement if built: must work identically on single-node and multi-node, driven through GitHub CI/CD, not just local `cloud:deploy`.**

This is the multi-project sibling of the existing single-project **Single-Node Hero** ($4-12/mo, one app) and **$6 Baseline** (1GB, one app) strategies. Those stay as-is.

## 🟢 Tier 0 — Two projects, one server, separate namespaces (start here)

The simple model the user actually asked for: two independent LaraKube repos, deployed to the same VPS, isolated by namespace. **This is largely already supported** because:

- Namespaces are `{name}-{environment}` — repo A and repo B never collide.
- Traefik is cluster-wide and routes by Host header — each repo keeps its own domain(s).
- `cloud:provision` preps the box, not a project — a second repo deploying to the same IP reuses the cluster.
- GitHub CI/CD (Cloud Pilot) already deploys per-repo; two repos = two workflows pointing at the same cluster.

Each project still runs **its own** data services in its own namespace, OR points at an external managed DB via the existing per-env `managed` field. No sharing, no Commons, no new commands.

**The only gaps to close for Tier 0 (small):**

1. **Provision idempotency.** Confirm `cloud:provision` on an already-provisioned box is a safe no-op (doesn't reinstall K3s, doesn't nuke the existing project's namespace/data). Today's flow should be audited and made explicitly re-entrant.
2. **No accidental cluster nuke on second deploy.** Verify `cloud:deploy` / the GHA workflow for repo B never deletes repo A's resources (it shouldn't — different namespaces — but prove it).
3. **Capacity reality.** Two full self-contained stacks (each its own Postgres/Redis/Meili) won't fit 2GB. So Tier 0's honest guidance is: on a 2GB box, run the heavy services as **external managed DB** (`managed` host → DO Managed DB), or keep one app lightweight (SQLite + database/file cache). Document this plainly; warn when it's likely to OOM.
4. **Docs.** A short "Two apps, one server" guide showing the two-repo → one-VPS → two-namespace flow end to end via CI/CD.

Tier 0 is mostly **audit + verify + document**, with at most a small idempotency fix. Ship this first.

> **Audit result (2026-05-30) — Tier 0 works today, no code needed:**
> - `cloud:provision` is **project-agnostic and re-run-safe**: swap (`if [ ! -f
>   /swapfile ]`), IP-forward (`grep -qxF || echo`), and the `larakube` user
>   (`if ! id`) are all guarded; Traefik is `kubectl apply` / `--dry-run|apply`.
>   The only heavy re-run is the K3s installer — so **provision once; for app B,
>   skip provision (or decline the K3s step).** No auto-"already provisioned"
>   detection yet (relies on confirm prompts) — minor UX gap, not a data risk.
> - **A second app cannot nuke the first.** `down` deletes only
>   `{name}-{env}` (its own namespace) and PVs via `-l larakube-project={name}`
>   (label-scoped to the app). `uninstall` additionally requires typing the
>   project name. App A's namespace + labeled volumes are untouched by app B.
> - **Net:** two repos → two namespaces → one shared cluster, Traefik routing by
>   host, each app running its own Postgres/Redis (or a managed DB). Deliverable
>   is a runbook, not code.

---

## 🏗️ Tier 1 — The Tenement (optional density tier, future)

Everything below is the *optional* shared-services optimization. Name the strategy **The Tenement** — one node ("the building") hosting multiple project **Tenants**, sharing common **Commons** utilities (Postgres, Redis, optionally Meilisearch) to reclaim the RAM that per-project data stacks waste. Only worth building if Tier 0 proves the demand and 2GB density becomes the real pain point.

**Non-negotiable design constraint:** the Tenement must work the same on single-node and multi-node, and be driveable through GitHub CI/CD — not a local-only `cloud:deploy` feature. A Tenant joining the Commons should be expressible in the GHA workflow so deploys stay GitOps, and the same blueprint that runs on a single-node Tenement must run unchanged when the Commons is later backed by a multi-node-friendly managed service.

## 🧠 The core insight (why this is mostly already built)

A project never declares "I share Postgres with another project." It declares, per environment, that a service is **`managed`** with a known host — the exact mechanism that already points production at AWS RDS. The Tenement reuses that seam:

| Topology | `managed` host for postgres |
|---|---|
| Local dev | _(not managed — runs its own pod)_ |
| Single-node Tenement | `postgres.larakube-plex.svc.cluster.local` |
| Graduated to managed DB / multi-node | `your-db.db.ondigitalocean.com` (or RDS, etc.) |

**The project's manifests never deploy Postgres in the last two cases, and the only thing that changes between them is a hostname.** That is the answer to "Can LaraKube handle the evolution without confusion?" — yes, because a Tenant's view of its database is just a connection string, and graduating off the Tenement is a one-line host change, not a manifest rewrite.

This is a direct payoff of the v0.4.0 per-environment schema: `environments[env].managed` + per-service hosts already express everything the Tenant side needs. Most of the new work is on the **shared Utilities** side and the **node-level orchestration**, not the project schema.

## 🧱 Feasibility — what already works today

- **Namespace isolation**: every project deploys to `{name}-{environment}` namespaces. Two projects never collide at the K8s object level.
- **Ingress multiplexing**: Traefik is cluster-wide and routes by Host header. Two projects on two domains "just work" through one ingress.
- **Project-agnostic provisioning**: `cloud:provision` preps the box (K3s, swap, Traefik, ACME) without baking in a single project, so a second project deploying to the same IP reuses the cluster.
- **Shared-namespace precedent**: `larakube-system` already hosts cluster-wide infra (Console, Traefik). The shared Utilities get an analogous `larakube-plex` namespace.

What's missing is: (1) a shared Utilities release, (2) per-tenant database/credential provisioning inside it, (3) resource limits so tenants can't starve each other, (4) a node inventory so the CLI knows who lives on the box, and (5) the host-resolution tweak for cross-namespace managed services.

## 🏛 Architecture

### 1. The Commons (shared Utilities release)

A LaraKube-managed bundle deployed once per node into a `larakube-plex` namespace:

- **Postgres** (single instance, multi-database)
- **Redis** (single instance, per-tenant logical DB index or ACL user)
- **Meilisearch** (optional — it's the RAM hog; off by default on 2GB, see Capacity)
- Each with **resource requests/limits** and a **PVC** on the node's disk.

The Commons is its own LaraKube-managed release (a dedicated shared-services bundle, not a normal user project). Provisioned via a new `larakube tenement init` (or folded into `cloud:provision` with a "host shared services?" prompt).

### 2. Tenants (the projects)

Each project joins the Tenement by marking its data services as `managed` in the relevant cloud env and pointing the host at the Commons FQDN. On join, LaraKube:

1. Creates a dedicated **database** + **Postgres role** + **password** for the tenant in the Commons Postgres (idempotent `CREATE DATABASE` / `CREATE ROLE` / `GRANT`, scoped so tenants can't cross-read).
2. Assigns a **Redis logical DB index** (or a Redis 6+ ACL user for the hardened path).
3. Writes the resulting connection values into the tenant's `.env.{env}` (or surfaces them for the user to paste).
4. Sets `environments[env].managed` to include the shared services so the tenant's manifests skip deploying them.

### 3. Resource fairness (the "soft / trusted" isolation posture)

Every workload (web, queues, horizon, the Commons services) gets **requests + limits**. This is what prevents Tenant A's traffic spike from OOM-killing Tenant B. No NetworkPolicies / ResourceQuotas / strict RBAC in the MVP — both projects belong to the same owner, so the trust boundary is the node, not the namespace. (Hardened multi-tenant is a documented future tier, not MVP.)

### 4. Node inventory

A lightweight record of which projects are deployed to a given node (label-based discovery via `larakube.io/node` or a small registry in the Commons), so `larakube` can:
- List tenants on a box.
- Warn before deploying a third tenant that the node is near capacity.
- Drive a `larakube tenement status` overview.

### 5. Ingress & TLS

No change needed — Traefik already multiplexes by host and ACME issues per-host certs. Each tenant keeps its own domain(s) and per-service hosts (Reverb subdomain, etc.) exactly as in the Blueprint Anatomy.

## 🔐 Security model (and how we explain it to K8s newcomers)

The headline for docs: **"Sharing Postgres on the Tenement is exactly like two apps sharing one AWS RDS instance — each app has its own database and its own login, and neither can see the other's data."** That reframes a scary-sounding "shared database" into a pattern hobbyists already trust.

Mechanism (soft/trusted MVP):
- **Postgres**: one role + one database per tenant, `GRANT`-scoped; no shared superuser in app creds. Tenant A's role has no rights on Tenant B's database.
- **Redis**: separate logical DB index per tenant by default; **Redis 6+ ACL user per tenant** documented as the stronger option (true key-space isolation).
- **Meilisearch**: scoped API keys per tenant (Meili supports tenant tokens / scoped keys).
- **Network**: MVP relies on namespace + credential isolation (trusted single-owner). NetworkPolicies called out as the hardened upgrade.

Docs must be explicit about what shared services do and don't isolate, so a newcomer makes an informed choice rather than absorbing a misconception.

## 🔁 The evolution path (local → Tenement → multi-node)

| Stage | Data services | What the Tenant blueprint says | Migration action |
|---|---|---|---|
| **Local dev** | Own pods in `{name}-local` | services NOT managed | none |
| **Single-node Tenement ($12)** | Shared Commons in `larakube-plex` | `managed: [postgres, redis]`, host → Commons FQDN | `larakube tenement join` |
| **Graduate: managed DB / bigger box** | Provider-hosted (DO Managed DB, RDS) | `managed: [postgres, redis]`, host → provider endpoint | change host in `.env`; re-deploy |
| **Multi-node HA** | Provider-hosted or dedicated | same as above | `strategy: cluster`; manifests already proven |

The crucial property: **local stays per-project isolated** (simplest for dev; RAM isn't the constraint locally), and the Tenement is purely a cloud-env topology. A developer never has to think about shared services while building locally.

## 🗄 Schema changes

Small, because the Tenant side is mostly covered by v0.4.0:

1. **Cross-namespace managed host resolution.** `ConfigData::getInternalFqdn()` currently hardcodes the project's own namespace (`{name}-{environment}`). Managed-but-in-cluster services need to resolve to `{service}.larakube-plex.svc.cluster.local`. Add an optional shared-namespace target (per-env field like `sharedServices: true` or a `managedHosts` map) so a managed service can point at the Commons without the user typing the FQDN by hand.
2. **Node binding (optional).** A `node` hint per cloud env (or in `cloud[env]`) so the inventory knows which box a tenant targets. Could be derived from the cloud IP instead — decide during design.
3. **Commons release.** A definition for the shared Utilities bundle (Postgres/Redis/Meili with limits + PVCs) deployed into `larakube-plex`.

No breaking changes to existing blueprints — a non-Tenement project is unaffected.

## 🧰 New / changed commands (Tier 1 only — Tier 0 needs none)

Verb is **`plex`** (see Resolved decisions). All commands must be
**CI/CD-expressible** (callable from the GHA Cloud Pilot workflow) and behave
identically whether the node is single- or multi-node. They are not part of Tier 0.

- `larakube plex init` — provision the Commons (Postgres + Redis in
  `larakube-plex`) on the cluster (or a `--shared` flag on `cloud:provision`).
- `larakube plex join` — register the current project as a Tenant: create its
  DB/role, assign a Redis index, write `.env`, set `managed`.
- `larakube plex status` — list tenants on the cluster + capacity/RAM headroom.
- `larakube plex leave` — deprovision a tenant's DB/credentials (strong confirm).
- `cloud:provision` — gains awareness that a box may already host the Commons
  (don't re-init, don't nuke). _(Audit below confirms today's provision is already
  re-run-safe.)_
- `cloud:deploy` — capacity pre-flight: warn if adding this tenant likely exceeds
  node RAM.

## 📊 Capacity guidance (must be in docs + enforced by warnings)

Rough budget for $12/2GB:
- k3s + Traefik: ~0.5GB
- Commons Postgres + Redis: ~0.3GB
- 2 × FrankenPHP web (modest workers): ~0.5GB
- Headroom / spikes / rollouts: ~0.7GB

→ **Two modest apps fit. Meilisearch-per-tenant does not** (Meili idles at ~256MB+). The CLI should warn when enabling Scout/Meili on a Tenement node and recommend either a shared single Meili (multi-index) or the next droplet tier. Three tenants → recommend $24/4GB.

## 🚦 Phased delivery

- **Phase 0 — Tier 0 (the simple version, do first).** Audit `cloud:provision` for idempotency; prove a second repo's deploy can't nuke the first's namespace; write the "Two apps, one server" CI/CD doc; add a capacity warning. Little-to-no new code. **This alone satisfies the user's actual near-term ask.**
- **Phase 1 — Commons release.** _(Tier 1 begins.)_ The shared Utilities bundle (Postgres + Redis) with limits + PVCs in `larakube-plex`. `tenement init`. No tenant wiring yet; validate the shared services stand up. Must be expressible in a GHA workflow, not local-only.
- **Phase 2 — Tenant join.** Per-tenant DB/role/password provisioning, Redis index assignment, `.env` writeback, `managed` wiring, cross-namespace FQDN resolution. One project runs against the Commons — verified via CI/CD deploy, not just local.
- **Phase 3 — Second tenant + fairness.** Resource requests/limits everywhere, node inventory, `tenement status`, capacity warnings. Two projects coexisting verified end-to-end.
- **Phase 4 — Graduation + multi-node + Meili + hardening docs.** Document the host-swap to managed DB, prove the same Tenant blueprint runs on a multi-node cluster unchanged (Commons backed by managed service), optional shared Meilisearch (multi-index), and the hardened-isolation upgrade (Redis ACL users, NetworkPolicies, ResourceQuotas).

## ✅ Verification

- Two demo projects on a real $12 droplet, each on its own domain, sharing one Postgres + Redis, each with its own database/credentials, neither able to read the other's data (prove with a cross-tenant query attempt that fails).
- Kill/OOM test: hammer Tenant A; confirm limits keep Tenant B serving.
- Graduation test: flip a tenant's Postgres host from Commons FQDN to an external managed DB; confirm only `.env` + redeploy is needed, no manifest surgery.
- Rollout test: deploy a new image to one tenant; confirm the other stays up (no shared-fate during rollouts).

## ⚠️ Risks / open questions

- **RAM ceiling is real.** 2GB is genuinely tight; the warnings must be honest or users will have a bad first experience. Consider making swap sizing Tenement-aware (larger swap when hosting multiple tenants).
- **Shared-fate on the Commons.** If the shared Postgres dies, all tenants are down. Acceptable for hobbyist tier, but docs must say so plainly.
- **Backup story.** One Postgres, many tenant databases → backup/restore granularity. Per-database `pg_dump` per tenant vs whole-instance snapshot. Decide in Phase 2.
- **Redis logical-DB isolation is weak** (no auth boundary between indexes). Default is fine for trusted single-owner; push ACL users in docs for anyone uneasy.
- **Node binding vs cloud IP.** Whether to add an explicit `node` field or derive identity from the cloud IP — resolve in design to avoid schema bloat.

## 🔗 Relationship to existing strategies & docs

- Sits above **Single-Node Hero** and **$6 Baseline** in the scaling story. Add a new docs page (`architecture/the-tenement.md`) and a row in the strategy-progression narrative.
- Reuses the **per-environment `managed` + per-service hosts** model documented in **Blueprint Anatomy** — link it as the prerequisite mental model.
- The **graduation path** dovetails with `strategy: cluster` (multi-node) — the Tenant blueprint that ran on the Tenement is already multi-node-ready because its data services were external all along.
