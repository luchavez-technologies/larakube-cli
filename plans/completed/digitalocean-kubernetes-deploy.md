# Plan: Bare-minimum deploy to DigitalOcean Kubernetes (DOKS)

**Status:** ✅ BUILT — verified 2026-08-08: `cloud:init:doks` ships, `ManagedProvider::DOKS` is a case, and `cloud:deploy` picks SSH-sideload vs registry-push automatically.

## 🎯 Objective

Get a LaraKube app running on **DigitalOcean Kubernetes (DOKS)** — managed,
multi-node — with the least new work, building on the shipped managed-k8s
overlay knobs (Phases 1–4). "Bare minimum" = pods `Running` and reachable;
HTTPS is a fast-follow.

This is the **managed-k8s path**, not tenement. (Tenement = many apps on one
box sharing services; see [tenement-multi-tenant-node.md](./tenement-multi-tenant-node.md).
Tenement can later run *on* DOKS, but a DOKS deploy is the prerequisite.)

## ✅ What already works (managed-k8s Phases 1–4)

| Need | Status |
|---|---|
| Ingress (DOKS commonly nginx; DO auto-provisions a LoadBalancer for it) | per-env `ingress: nginx` ✅ |
| Image + pull secret (GHCR + `ghcr-login` works on DOKS) | ✅ (DOCR/others = the registry plan) |
| Namespace / ServiceAccount overrides | ✅ Phases 2–3 |
| Storage: RWO PVCs land on DOKS default `do-block-storage` | ✅ for `single-node` strategy |
| Deploy: GHA `kubectl kustomize | sed | apply` against a kubeconfig | ✅ point it at the DOKS kubeconfig |

## ⚠️ Gaps to close for a clean DOKS experience

1. **storageClass knob (small).** PVCs currently omit `storageClassName`, so they
   use the cluster default — fine on DOKS (`do-block-storage`), but there's no way
   to *name* a class, and `multi-node-ha` (RWX) won't work on block storage.
   - Add optional per-env `storageClass` (on `EnvironmentData`), emitted into the
     app-volumes PVCs when set; unset = cluster default (snapshot-stable).
   - For HA, the honest answer is a **managed DB** (`managed: [postgres]` → DO
     Managed Postgres) so the app needs no RWX volume.
2. **TLS on a managed cluster (real gap).** Auto-certs only exist for
   Traefik + `single-node` (Let's Encrypt resolver). On DOKS/nginx the path is
   **cert-manager** (ClusterIssuer + `cert-manager.io/cluster-issuer` annotation).
   - Bare minimum: start on HTTP, or hand-add the cert-manager annotation via the
     per-env `ingressAnnotations` knob (already shipped).
   - Follow-up: a first-class TLS-on-managed option (install cert-manager +
     emit the issuer annotation) — own small plan.

## 🧩 Bare-minimum DOKS blueprint (worked example)

```json
"environments": {
  "production": {
    "ingress": "nginx",
    "strategy": "single-node",
    "managed": ["postgres", "redis"],
    "hosts": { "web": "app.example.com" },
    "ingressAnnotations": {
      "cert-manager.io/cluster-issuer": "letsencrypt-prod"
    }
  }
}
```
- `nginx` ingress → DO provisions a LoadBalancer; point your DNS at its IP.
- `managed: [postgres, redis]` → use DO Managed Postgres/Redis; set the real
  hosts in `.env.production` (no RWX needed).
- `single-node` strategy → any remaining app PVCs are RWO on `do-block-storage`.
- cert-manager annotation → HTTPS once cert-manager + a ClusterIssuer are installed
  (or omit and start on HTTP).

## 🚦 Steps (runbook shape)

1. Create the DOKS cluster; pull its kubeconfig (`doctl kubernetes cluster
   kubeconfig save <cluster>`), set it as the active context.
2. Install the nginx ingress controller (DO 1-click/marketplace or Helm) →
   note the LoadBalancer IP; point DNS at it.
3. (HTTPS) install cert-manager + a Let's Encrypt `ClusterIssuer`.
4. Create the GHCR pull secret in the app namespace (or use the registry plan's
   per-env handling once built).
5. `larakube cloud:configure gha` for `production`, with the **DOKS kubeconfig**
   as the context, so the workflow's `kubectl apply -k` targets DOKS.
6. Push → the GHA pipeline builds, pushes to GHCR, and applies
   `overlays/production` to DOKS.

## 🚦 Phases (code)

1. **storageClass knob** — `EnvironmentData.storageClass` + resolver + render into
   app-volumes PVCs (snapshot-stable when unset). Smallest, do first.
2. **TLS-on-managed (optional)** — cert-manager issuer annotation as a first-class
   option (vs. raw `ingressAnnotations`); possibly a `cloud:provision`-style
   helper to install cert-manager. Own plan if it grows.
3. **Docs** — the DOKS runbook above as a docs page.

## ✅ Verification

- A `nginx` + `single-node` + managed-DB blueprint applies cleanly to a real DOKS
  cluster; web pod reachable via the LB.
- With the `storageClass` knob set, the PVC requests that class; unset = default
  (snapshot unchanged).
- HTTPS works once cert-manager + issuer are present and the annotation is set.

## ⚠️ Risks / open questions

- **RWX is a trap on block storage.** Steer multi-node users to a managed DB
  rather than RWX PVCs; warn if `multi-node-ha` + an in-cluster DB is configured.
- **cert-manager bootstrapping.** Installing it is a cluster prerequisite — decide
  whether LaraKube installs it (like Traefik on `cloud:provision`) or documents it.
- **DOCR vs GHCR.** GHCR works immediately; DO Container Registry is just another
  provider in [per-environment-registry.md](./per-environment-registry.md).
- **Ingress controller install.** LaraKube doesn't install nginx on DOKS today —
  document the 1-click, or add an install helper later.
