# Plan: LaraKube Platform — The Ploi Alternative Built on Kubernetes

> **Superseded by `plans/active/larakube-cloud.md`** for the LaraKube Cloud product plan — that file
> folds in this one's Ploi comparison and phase framing, adds technology grounding for VPS→K8s migration
> (Velero), multi-provider/BYOC provisioning, fleet monitoring, and ephemeral dev sandboxes, and is kept
> up to date against the current codebase. Left in place for the comparison table and phase detail below,
> which are still accurate; don't extend this file further — extend the new one.

> **Vision:** A hosted server-management platform for Laravel developers that starts as simple as Ploi but grows with you — from a $6 VPS to a multi-region managed Kubernetes cluster, **with zero re-architecture**.
>
> The core insight Ploi misses: Kubernetes is the right abstraction from day one. Everything LaraKube already does (manifests, namespaces, rolling updates, offline bundles, Plex multi-tenancy) becomes the engine of a polished product.

---

## 🥊 Where LaraKube Wins vs Ploi

| Capability | Ploi.io | LaraKube Platform |
|---|---|---|
| Traditional server deploy | ✅ | (via CLI, not target market) |
| Laravel-native workflows | ✅ | ✅ |
| Multiple apps per server | ✅ (per-site) | ✅ (Kubernetes namespaces) |
| Zero-config SSL | ✅ Let's Encrypt | ✅ Traefik + ACME |
| Git auto-deploy | ✅ GitHub / GitLab | ✅ GHA + GitLab CI |
| **Migrate VPS → managed K8s** | ❌ Start over | ✅ **Same blueprint, new target** |
| **Air-gapped / offline bundles** | ❌ | ✅ USB-stick enterprise deploy |
| Immutable infrastructure | ❌ mutable server state | ✅ Kubernetes pods |
| Infrastructure as code | ❌ UI-only | ✅ `.larakube.json` in git |
| ARM / Raspberry Pi | ❌ | ✅ `--arch=arm64` |
| **Lock-in escape hatch** | ❌ Ploi-specific | ✅ plain Kubernetes — works anywhere |

**The headline differentiator:** when a Ploi customer outgrows their single VPS, they have to migrate manually to a completely different system (Forge → Vapor, or build their own K8s setup). LaraKube customers run `larakube cloud:deploy production` or flip to DOKS — same code, same `.larakube.json`, same CI workflow.

---

## 🏗 Architecture: CLI Engine + Thin Platform Layer

LaraKube Platform is **not a rewrite**. The CLI remains the authoritative engine. The platform adds:

```
┌─────────────────────────────────────────────┐
│           LaraKube Platform (web)           │
│  Dashboard · Webhooks · Billing · API keys  │
└──────────────┬──────────────────────────────┘
               │  calls / wraps
┌──────────────▼──────────────────────────────┐
│           LaraKube CLI (existing)           │
│  cloud:provision · deploy · bundle · plex   │
└──────────────┬──────────────────────────────┘
               │  manages
┌──────────────▼──────────────────────────────┐
│  Customer clusters (VPS / DOKS / EKS / GKE) │
└─────────────────────────────────────────────┘
```

The platform never owns the customer's server. It orchestrates the CLI against infrastructure the customer controls — so they can always `git push` or run the CLI directly if they ever cancel.

---

## 🌐 Product Phases

### Phase 1 — "Ploi but Kubernetes" (The Beachhead)

**Goal:** any Laravel developer can provision a VPS and deploy an app in under 10 minutes from the web dashboard. Compete directly with Ploi on ease-of-use.

**What it does:**
- Web dashboard to connect cloud provider accounts (DigitalOcean, Hetzner, Vultr, AWS, any SSH-accessible VPS)
- One-click server provisioning (`cloud:provision` under the hood)
- Add an app: point at a GitHub / GitLab repo + branch
- Automatically generate and store the GHA / GitLab CI workflow
- Dashboard shows deploy status, live logs, rollout health
- Manage environment variables (`.env.$environment`) from the UI — no more manual SSH
- Automatic SSL via Traefik + Let's Encrypt
- Custom domains with DNS guidance

**Key technical pieces needed in CLI:**
- `cloud:provision` already done ✅
- `cloud:configure --only=ci` (was `cloud:configure:gha`, merged in `paas-core-expansion.md` §7.2) already done ✅
- ~~GitLab CI provider (`plans/active/gitlab-ci-provider.md`) — prioritise~~ — **already built** (`ConfiguresCloudEnvironment::configureGitlab()`, `.gitlab-ci.yml` generation, auto-detected by `cloud:configure --only=ci`); that plan file is stale and should be archived, not prioritised
- An API / machine-readable output mode so the platform can parse CLI output
- Webhook receiver for `git push` → trigger deploy

**Monetisation:** free up to 1 server + 1 app; paid tiers by server count or app count.

---

### Phase 2 — Multi-App, Multi-Server (The Plex Story)

**Goal:** a team can run 5 apps on 2 servers from one dashboard, with shared databases via Plex.

**What it does:**
- Dashboard view of all servers + all apps (namespaces) across them
- One-click Plex Commons setup: "share Postgres + Redis between these 3 apps on this server"
- Cross-app environment linking (e.g. app B's `DB_HOST` auto-filled from the Commons)
- Server health overview: CPU, memory, namespace resource usage (via `kubectl top`)
- Invite teammates with role-based access (`cloud:cluster:users` → dashboard UI)

**Key technical pieces:**
- Plex `plex:join` / `plex:init` / `plex:export` already built ✅
- RBAC teammate access (`plans/active/rbac-teammate-access.md`) — prioritise
- `cloud:cluster:users` → surfaced in dashboard

---

### Phase 3 — The Escape Hatch (The Migration Story)

This is where Ploi customers permanently convert.

**The pitch:** "You're paying $50/mo on Ploi for 4 apps on 2 servers. Migrate them all to a single DOKS cluster in 20 minutes, save money, and never be locked in again."

**What it does:**
- "Upgrade server → Kubernetes" wizard in the dashboard
- Detects apps on a VPS, shows what Plex Commons they share
- Spins up a managed cluster (DOKS, Civo, LKE) via provider API
- Migrates `.larakube.json` overlay targets to the new cluster
- Re-points CI/CD workflows
- Old VPS decommission checklist

**Technical pieces:**
- DOKS provisioning already mostly done (`plans/active/digitalocean-kubernetes-deploy.md`) ✅
- Managed K8s overlay compatibility (`plans/active/managed-k8s-overlay-compatibility.md`) ✅
- Provider API integrations: DO already exists; Hetzner/Civo/LKE/Vultr to add

---

### Phase 4 — Enterprise (The Bundle Story)

For teams that already use air-gapped bundles — turn it into a first-class product tier.

**What it does:**
- "Enterprise delivery" dashboard: create a bundle, track which client version is installed
- Bundle update portal: push lightweight `--update` bundles to client servers
- Offline dashboard view (client installs a read-only status agent alongside the bundle)
- Signed bundles with tamper-evident checksums

---

## 🔌 Provider Integrations Priority

| Provider | Type | Priority | Notes |
|---|---|---|---|
| DigitalOcean | VPS + DOKS | P0 | Already deeply integrated |
| GitHub | Git + CI | P0 | GHA workflow done |
| GitLab | Git + CI | P1 | Plan exists, not built |
| Hetzner | VPS | P1 | Cheapest bare metal, popular in EU |
| Vultr | VPS | P1 | Popular alternative to DO |
| Civo | Managed K8s | P1 | K3s-native, fastest cluster spin-up |
| Linode (Akamai) | VPS + LKE | P2 | Solid, price-competitive |
| AWS | EC2 + EKS | P2 | Enterprise segment |
| Google Cloud | GCE + GKE | P2 | Enterprise segment |
| Bitbucket | Git + CI | P3 | Smaller market |

---

## 💡 Positioning Statement

> "LaraKube is the server management platform that doesn't trap you. Start on a $6 VPS like Ploi, run multiple apps like Forge, and migrate to managed Kubernetes — without changing a single line of infrastructure code."

The three things no competitor can say at once:
1. **As simple as Ploi** to get started
2. **As powerful as Kubernetes** when you grow
3. **No lock-in** — your `.larakube.json` is plain JSON, your cluster is standard K8s, your CI is a YAML file in your own repo

---

## 🚦 What Needs to Be Built First (CLI Gaps)

Before the platform UI is worth building, close these CLI gaps:

1. **GitLab CI provider** — parity with GHA (plan exists)
2. **Machine-readable output** — `--json` flag on key commands so the platform can parse status without scraping terminal output
3. **RBAC teammate access** — platform needs scoped tokens per team member (plan exists)
4. **Plex full suite** — `plex:migrate` data-copy + MySQL/MariaDB alloc (Phase 2 of existing plex plan)
5. **`cloud:logs` command** — stream pod logs; platform surfaces them live in the dashboard
6. **`cloud:status` command** — cluster health summary (deployments, pods, ingress IPs); platform dashboard widget

The platform UI itself is a separate project (likely a Laravel app — dogfooded on LaraKube, of course).
