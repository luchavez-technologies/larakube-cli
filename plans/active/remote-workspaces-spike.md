# Remote workspaces: Step 0 spike (GCP, throwaway)

**Goal:** measure one hand-built workspace so the design rests on numbers, not guesses. Decides server sizing guidance, whether a VPN-only editor is practical, and what the pod needs. Context: `project_remote_workspaces_plan` (memory); the workspace runs on the developer's own server.

**Server:** one new GCP VPS, `ws-spike`, `e2-standard-2` (2 vCPU, 8 GB), us-central1, made with `cloud:create`. Paid from the user's free credits (about $0.07/hour). Destroyed at the end with `cloud:destroy`. The existing `gcp-test-vps` is left alone.

**Steps**
1. Create the server; record baseline RAM (`free`, `kubectl top`) with k3s idle.
2. A `ws-spike` namespace: PVC, Deployment with an editor image (code-server) plus a PHP/Composer/Node/git dev image, a Service and an Ingress.
3. Clone a small Laravel app (public repo), `composer install`, `npm install`, run `artisan serve` and `vite`; open the app through the ingress.
4. Measure: idle and active RAM (pod and node), peak during installs, cold start from scale 0, time to a usable editor.
5. Check access: editor reachable only by an auth token or the cluster's port-forward, not public; note what SSO or VPN would need.
6. Write the numbers and surprises into this file; destroy the server.

**Rules:** this is an experiment on a throwaway server, so hand-made manifests are fine here. Nothing from it ships; anything that becomes a feature is rebuilt as CLI commands and templates. No secrets from the user's machines go on the server.

**Done when:** numbers recorded below, server destroyed, and a go/no-go on Step 1.

## Results (GCP `e2-standard-2`, 2 vCPU, 7.9 GB, Ubuntu 24.04, k3s v1.36.2)

**Memory** (a fresh `laravel/laravel` app; a big app will be heavier)

| State | Memory |
| --- | --- |
| Node, k3s + Traefik + CoreDNS + metrics idle | 0.9 to 1.1 GB (about 6.8 GB free) |
| Workspace pod, editor server only, no browser | 55 MiB |
| Editor with one browser client attached | about 0.5 GB |
| Editor + Intelephense installed and a PHP file open + `artisan serve` + `queue:work` + `vite` | 0.85 GB working set (cgroup 0.9 GB, peak 0.96 GB) |
| Peak during `composer install` / `npm install` | about 0.3 GB / 0.75 GB (includes page cache) |

Intelephense did not show up as a large process on this small project; its index grows with `vendor/`, so a real app will use more. **Planning figure: 1 GB for a small app, 2 to 2.5 GB for a real one, 4 GB limit.** A 8 GB node fits about 3 real workspaces with headroom (6 to 7 small ones).

**Timing**
- Image import over SSH (463 MB, no registry): about 100 s once per server.
- Pod ready from scale 0: **4 s**; editor answering: **9 s**. The volume keeps the repo, `vendor/` and extensions.
- `composer install` 9 s, `npm install` 19 s, `vite build` 1 s (fresh Laravel, GCP network).
- Suspended workspace: node memory back to about 0.9 GB, so idle workspaces cost nothing in RAM.

**Isolation and access**
- By default another namespace could reach the editor (HTTP 200). A NetworkPolicy allowing only Traefik in stopped it. k3s enforces NetworkPolicy, so isolation is real.
- The workspace pod **can reach the Kubernetes API** and gets a **service account token mounted by default**. Both must be switched off for a workspace (no automounted token, an egress rule blocking the API) before an AI shell runs in it.
- The editor was reached only through `kubectl port-forward` (no public ingress). Public or VPN-only access through Traefik was not tested; that is the next piece (needs the wildcard host and certificate).

**Bugs this found in LaraKube, both fixed in the CLI**
1. A GCP VM with a long project ID gets a host name over 63 bytes, so the Kubernetes node never registered and every pod stayed Pending. k3s now uses `--node-name=$(hostname -s)`.
2. A re-created server can get a freed IP whose old host key is still in `known_hosts`, so hardening, user creation and the k3s install all failed. `cloud:create` and `cloud:destroy` now clear the key for that address.

**Not measured:** a large real app, Intelephense on a big `vendor/`, PhpStorm via JetBrains Gateway, the MCP, VPN-only and SSO access, two workspaces at once.

**Go/no-go on Step 1: go.** The pod is small, resumes in seconds, and isolates cleanly once the API access is closed. Decisions for Step 1: no service account token, a default NetworkPolicy, quotas, an image built once and shipped by SSH sideload (as `cloud:deploy` does), and the editor behind Traefik with SSO or VPN-only.
