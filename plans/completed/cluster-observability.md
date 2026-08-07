# Plan: Cluster Observability — Loki + Prometheus + Grafana

**Status:** ✅ BUILT — verified 2026-08-08: `monitor:init`/`monitor:show` ship; Prometheus + Grafana + kube-state-metrics Running on `larakube-159.89.205.239` (`monitor.luchtech.dev`). Loki + Promtail are fully implemented too — Deployment, `loki-storage` PVC, Promtail DaemonSet, push endpoint and Grafana datasource — behind `--with-logs`/`--no-logs`, which defaults ON interactively and OFF non-interactively. They are absent from this cluster because logs were declined at install, not because anything is missing. `larakube monitor:init --with-logs` deploys them.

> **Related:** [`monitoring-plex-storage.md`](monitoring-plex-storage.md) — queued
> enhancement to reuse the Plex Commons object store for Loki chunks (auto-detected,
> PVC fallback). Post-v0.21.x; pairs with the Gitea/Plex-MinIO work.

## Context

The LaraKube CLI roadmap ("Console: Real-time Prometheus / Grafana monitoring built in") has
carried this as a future item since v0.10. This plan makes it concrete with a
specific stack choice and K8s design.

The existing `larakube health` / `larakube status` / `larakube logs` commands cover
point-in-time inspection. This plan covers **time-series metrics + log aggregation +
dashboarding** — the "PLG" stack (Prometheus + Loki + Grafana).

---

## Stack

| Component | Image | Role |
|---|---|---|
| **Prometheus** | `prom/prometheus` | Metrics scraper & TSDB |
| **Loki** | `grafana/loki` | Log aggregation + query |
| **Promtail** | `grafana/promtail` | DaemonSet — ships pod logs to Loki |
| **Grafana** | `grafana/grafana` | Dashboards, auto-provisioned datasources |

All deployed to the **`kube-monitoring`** namespace.

---

## Design

### RBAC

Prometheus needs cluster-wide read access to scrape pod/node metrics:

```yaml
kind: ClusterRole
rules:
  - apiGroups: [""]
    resources: [nodes, nodes/proxy, services, endpoints, pods]
    verbs: [get, list, watch]
  - nonResourceURLs: ["/metrics"]
    verbs: [get]
```

Loki/Grafana are namespace-scoped — they don't need cluster RBAC.

### Prometheus scraping

Use **Kubernetes Service Discovery** (`kubernetes_sd_configs`) with a relabeling
rule to keep only pods/namespaces that carry the label:
`prometheus.io/scrape: "true"`

This makes observability **opt-in per app**. LaraKube CLI adds the label when
`larakube up` / `larakube heal` detects `APP_MONITORING=true` in `.larakube.json`.

### Namespace isolation

Grafana queries are scoped per-tenant via the `{namespace="<app>"}` label selector
in Loki. This means a developer querying their app's logs only sees their namespace —
they cannot accidentally query `kube-system` or another app's logs.

### Grafana auto-provisioning

Grafana datasources and dashboards are provisioned via ConfigMaps mounted at startup
(not manually configured in the UI). LaraKube CLI generates these from templates
in `resources/views/k8s/monitoring/`.

Auto-provisioned datasources:
- Prometheus → `http://prometheus.kube-monitoring.svc:9090`
- Loki → `http://loki.kube-monitoring.svc:3100`

Auto-provisioned dashboards:
- **App Overview** — request rate, error rate, memory, CPU per pod
- **K3s / Node metrics** — disk, network, load
- **Laravel Horizon** — queue throughput, failed jobs, wait time (if Horizon is enabled)
- **Database health** — MariaDB/Postgres connection count, query latency

---

## Phases

### Phase 1 — New `larakube monitor:init <env>` command

Generates the full `kube-monitoring` namespace manifests and applies them.
Steps:
1. Check if `kube-monitoring` namespace exists (idempotent)
2. Apply ClusterRole + ClusterRoleBinding for Prometheus SA
3. Apply Prometheus ConfigMap (scrape config) + Deployment + Service
4. Apply Loki ConfigMap + StatefulSet (local storage, no object store in Phase 1) + Service
5. Apply Promtail DaemonSet
6. Apply Grafana Deployment + Service + ConfigMaps for datasources + dashboards
7. Apply Traefik IngressRoute exposing Grafana on a sub-path or subdomain
   (protected by basic auth middleware — credentials printed at end)
8. Wait for all pods Ready

Flag `--no-ingress` skips step 7 (for private clusters without external access).

### Phase 2 — `larakube up` / `larakube heal` integration

When the project has a `monitoring` block in `.larakube.json`, `larakube up`
automatically adds `prometheus.io/scrape: "true"` and port annotations to the
web, horizon, and reverb pods.

Laravel app requires the `promphp/prometheus_client_php` exporter (or similar) to
expose `/metrics` — LaraKube CLI can install this via `monitor:init --php-exporter`.

### Phase 3 — Loki object store (S3 / MinIO)

Replace Loki's local filesystem storage with object storage (MinIO or Plex's
SeaweedFS) so logs survive pod restarts and PVC resizes aren't needed.

### Phase 4 — Console integration (future)

Surface key metrics in `larakube about` / the LaraKube CLI Console (if Console
gets a TUI upgrade): live pod CPU/memory gauges, error rate badge.

---

## Resource budget

These are light deployments — appropriate for a $12/mo 1-CPU-2GB-RAM VPS:

| Component | CPU req | Mem req |
|---|---|---|
| Prometheus | 50m | 256Mi |
| Loki | 50m | 128Mi |
| Promtail (per node) | 10m | 64Mi |
| Grafana | 50m | 128Mi |

Total: ~160m CPU + 576Mi memory added to the cluster. Fits comfortably on the
same node alongside a Plex stack.

---

## Open questions

- Should monitoring be opt-in (explicit `monitor:init`) or auto-installed
  on every `cloud:provision`? Lean opt-in — not every project needs metrics on day 1,
  and it adds 576Mi pressure on small nodes.
- Grafana auth: basic auth via Traefik middleware (simplest) vs Grafana's built-in
  OAuth (integrates with GitHub auth for team access)?
- Alerts: Prometheus AlertManager is deliberately out of scope for Phase 1. Add in
  Phase 2+ if there's demand.
