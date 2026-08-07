# speckit.plan: Per-Environment Deployment Strategy

**Status:** ✅ BUILT — verified 2026-08-08: `ConfigData::getStrategy($env)` is per-environment and drives PVC access modes and the multi-node emptyDir swap.

## 🎯 Objective

Let `strategy` (single-node vs multi-node-HA) vary per environment instead of
being one project-wide value — so a budget-conscious team can run **staging
single-node** (cheap: one VPS, hostPort Traefik + Let's Encrypt, RWO volumes,
1 replica) and **production multi-node-HA** (HA: load balancer, RWX volumes,
2+ replicas) from the same blueprint.

This is the natural sibling of the per-env `ingress` / `managed` / `hosts`
move (v0.4.0) and follows the same principle: fields that legitimately differ
per environment belong on `EnvironmentData`, not top-level.

## 🧩 What `strategy` actually controls

`DeploymentStrategy` = `single-node` | `multi-node-ha`. Two tiers of usage:

**A. Overlay-level (already environment-scoped — easy):**
- `overlays/.../deployment-patch.yaml` — replicas (`MULTI_NODE_HA ? 2 : 1`)
- `k8s/ssr/deployment.yaml` — replicas (written per cloud env)
- `overlays/.../ingress-patch.yaml` — Let's Encrypt certresolver + TLS secret (single-node only)
- `cloud:configure gha` — GHCR pull-secret step gated on single-node

These templates already receive `$environment`, so they just switch from
`getStrategy()` to `getStrategy($environment)`.

**B. Base-level (the wrinkle):**
- `base/volumes.yaml`, `base/pvc.yaml` — PVC `accessMode`: `ReadWriteOnce`
  (single-node) vs `ReadWriteMany` (multi-node)
- driver volume templates (`postgres/volumes.yaml`, `minio/volumes.yaml`, …)
  also pick RWO/RWX — but these are **already written per env** (the
  updateK8s loop passes `$environment`), so they're fine.

The snag is `base/volumes.yaml` + `base/pvc.yaml`: they live in `base/`, which
is shared by every overlay. A single base PVC can't be RWO for staging and
RWX for production at once.

## 🔑 The core design decision: base PVC access mode

Pick one:

1. **Per-env PVC patch** — keep the PVC in `base/` with a default accessMode,
   and emit a per-env kustomize patch overriding `accessModes` where the env's
   strategy differs from the default. Localized, but kustomize patching of PVC
   spec is fiddly (some fields immutable; may need recreate).
2. **Move app PVCs into per-env overlays** — generate `base/volumes` + `pvc`
   per environment instead of in `base/` (mirror what driver volumes already
   do). Cleanest conceptually and makes strategy fully per-env, but it's a
   base→overlay restructure with snapshot churn.
3. **Hybrid: top-level default for base PVCs, per-env for the rest** — keep a
   top-level `strategy` that governs only base PVC accessMode, while
   replicas/ingress/driver-volumes read `getStrategy($env)`. Smallest change;
   downside is base PVC accessMode still can't differ per env (acceptable if
   RWX-everywhere or RWO-everywhere base storage is tolerable — e.g. the app
   code/storage PVC is often fine as RWX regardless).

**Leaning option 2** for consistency (everything env-aware, no shared-fate),
but it's the most work. Option 3 unblocks the user's actual use case
(staging=single, prod=multi for **replicas + ingress**, which is the visible
difference) with minimal risk — base storage accessMode is rarely the thing a
budget tier cares about. Decide based on whether base PVCs genuinely need to
differ RWO/RWX per env.

## 🧱 Schema + API

```php
class EnvironmentData {
    // … existing …
    public ?DeploymentStrategy $strategy = null;   // per-env override
}
```

`ConfigData::getStrategy(?string $env = null)`:
- with `$env`: `environments[$env]->strategy ?? <default>`
- `<default>`: either the retained top-level `strategy` (option 3) or
  `SINGLE_NODE` (options 1/2, fully per-env like ingress).

Whether to keep top-level `strategy` depends on the base-PVC decision above.

## 📂 Files

- `app/Data/EnvironmentData.php` — add `strategy`.
- `app/Data/ConfigData.php` — `getStrategy(?string $env)`; decide top-level fate.
- Templates switching `getStrategy()` → `getStrategy($environment)`:
  `overlays/production/{deployment-patch,ingress-patch}.blade.php`,
  `k8s/ssr/deployment.blade.php`, driver `*/volumes.blade.php`,
  and (per the base-PVC decision) `base/volumes.blade.php`, `base/pvc.blade.php`.
- `app/Commands/Cloud/CloudConfigureCommand.php` — `getStrategy($env)` for the env being configured.
- Wizards (`GathersInfrastructureConfig`, `InteractsWithDynamicOptions`) — prompt strategy per cloud env (or default + per-env override).
- `larakube env` wizard — offer a strategy choice for the new env.
- Tests: `ConfigDataTest`, `KustomizeIndentationTest`, `EnvironmentOverlayTest`, snapshots.

## 🧪 Verification

- A blueprint with `staging.strategy = single-node`, `production.strategy = multi-node-ha`:
  - `kustomize staging` → web replicas 1, Let's Encrypt certresolver + TLS secret present.
  - `kustomize production` → web replicas 2, no certresolver (LB-terminated), RWX where applicable.
- Local + single-strategy projects: output unchanged (snapshot guard).

## ⚠️ Notes / risks

- **Timing:** raised while the project is stabilizing (post-broken-release). This
  is another breaking-ish schema touch; sequence it after stabilization unless
  the budget-tiered deploy is needed sooner.
- **Local is always single-node** (k3d/k3s, 1 replica) — `getStrategy('local')`
  should resolve to single-node regardless; only cloud envs vary.
- Correlated with ingress: multi-node-HA prod typically pairs with an LB/ALB
  ingress (see managed-K8s overlay plan) — the two per-env knobs compose.
