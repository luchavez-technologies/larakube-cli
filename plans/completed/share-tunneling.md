# Plan: `larakube share` — Persistent Tunnel Deployments

**Status:** ✅ BUILT — verified 2026-08-08: the ephemeral case (`share`) was already shipped when this was written; the persistent case now ships too as `cloud:configure:tunnel` (with `--remove`), backed by `TunnelProvider` (Cloudflare, Localtonet) and a `tunnel` field on `EnvironmentData`.

## Context

There are two distinct tunneling use cases in LaraKube CLI today:

1. **Local dev tunnel** (`larakube share`, already shipped): exposes the local
   k3d cluster to the internet temporarily via a Cloudflare Tunnel for demos/
   reviews. CLI-managed, ephemeral.

2. **Production tunnel-as-deployment** (this plan): a *always-on* Kubernetes
   Deployment that allows apps hosted behind CGNAT (Pi at home, CGNAT VPS,
   on-prem without open ports) to be reachable from the internet. Related to
   `arm-edge-deploy.md` Phase 3 but specifically for cloud environments, not just
   ARM edge.

The `arm-edge-deploy.md` Phase 3 covers the bundle/airgap tunnel path via
systemd. This plan covers the **K8s-native Deployment tunnel path** for VPS and
managed clusters.

---

## Design

### Namespace

Both tunnel Deployments run in the **app's namespace** (not `kube-system`).
Rationale: each app can have its own tunnel token/credentials. A shared
`larakube-tunnels` namespace is an alternative if multiple apps share one tunnel
(multi-app plans on one tunnel account).

### Traffic flow

```
Internet
  → Cloudflare/Localtonet edge
  → tunnel pod (in app namespace)
  → traefik.kube-system.svc.cluster.local:80
  → Traefik (splits by Host header)
  → web service / reverb service
```

Traefik's host-header routing already handles the web ↔ Reverb split — no
changes needed to Ingress manifests.

### Strategy: Cloudflare first, Localtonet fallback

LaraKube CLI reads tokens in order:
1. `CLOUDFLARE_TUNNEL_TOKEN` present → deploy cloudflared
2. `LOCALTONET_AUTH_TOKEN` present → deploy localtonet
3. Neither → prompt user which they want, store token in env/secret

**Do not** detect fallback via CrashLoopBackOff watching (fragile). Instead:
explicit preference set at configure time, persisted in `.larakube.json` as
`environments.<env>.tunnel.provider` (`cloudflare` | `localtonet` | `none`).

---

## Phases

### Phase 1 — Cloudflare Tunnel deployment

- New `larakube cloud:configure:tunnel <env>` command:
  - Prompts for `CLOUDFLARE_TUNNEL_TOKEN` (or reads env)
  - Stores encrypted secret in K8s: `larakube-tunnel-secret`
  - Writes `.larakube.json` `tunnel.provider = cloudflare`
  - Deploys `cloudflare/cloudflared:latest` Deployment to app namespace:
    - Args: `tunnel --no-autoupdate run --token $(CLOUDFLARE_TUNNEL_TOKEN)`
    - LivenessProbe: `httpGet /ready :2000`
    - Resources: `requests: cpu 10m, memory 32Mi` / `limits: cpu 100m, memory 64Mi`
  - Skips generating a Traefik Ingress (Cloudflare tunnel bypasses Traefik's
    entrypoint for the tunnel itself — traffic still flows through Traefik
    internally via the ClusterIP)

- `larakube heal` re-applies the tunnel Deployment if `tunnel.provider` is set

### Phase 2 — Localtonet fallback

- Same configure flow, `tunnel.provider = localtonet`
- Deploy `localtonet/localtonet:latest`:
  - Command: `/app/localtonet --authtoken <TOKEN>`
  - No health endpoint → use TCP probe on container startup instead
- Both providers write the same tunnel secret key; provider drives which image/args

### Phase 3 — `larakube share` local dev upgrade

- Extend the existing local `share` command to use the same K8s Deployment
  approach (instead of running cloudflared as a host process)
- Adds a `--localtonet` flag as an alternative to Cloudflare for devs who prefer it
- Aligns local and cloud tunnel mechanics

---

## Open questions

- Should `cloud:configure:tunnel` be a sub-command of `cloud:configure` (the
  existing full flow) or a standalone command? Lean toward standalone — tunnel
  is optional and most VPS deploys don't need it.
- For multi-app (Plex) setups: should there be one tunnel per app namespace, or
  a shared `larakube-tunnels` namespace with routing rules per tenant? The
  per-namespace approach is simpler; shared reduces the number of tunnel tokens
  a user needs.
