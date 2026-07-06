# Plan: Plex in Local Dev Environments

## Context

`plex:init` and `plex:join` currently guard against `local` environments
(the K3D-backed dev cluster). The guard exists because:

1. The local cluster is ephemeral — Plex commons data would be lost on `larakube down`
2. Most local dev testing uses a per-app MariaDB/Redis, not shared Plex infra
3. The `larakube-plex` namespace would add ~250m CPU + memory pressure to the dev node

That said, Plex on local **would be useful** for:
- Testing multi-tenant flows (e.g. verifying `plexTenantIdentifier()` isolation)
- Simulating "two envs on one server" locally before deploying to a $12 VPS
- End-to-end testing of `plex:join` and `plex:leave` without needing a live server

## Design

### Remove (or relax) the `local` guard

Currently `PlexInitCommand` checks `if ($env->type === 'local') { error / return; }`.
The guard should become a **warning** instead of a hard stop:

```
⚠️  You are initialising Plex in a local environment.
    Plex commons data (databases, object storage) will be lost when you run
    larakube down. Use a cloud environment for persistent Plex deployments.
    Continue anyway? [y/N]
```

The warning is surfaced because the dev node is ephemeral — the commons themselves
are fine on K3D (no persistent volume issues), but the *data* is non-persistent.

### Plex status on local

`plex:status` should work unchanged on local once the guard is relaxed — it
reads from the live cluster regardless of environment type.

### `larakube up` with Plex

When the active environment is `local` and has `plex.role = tenant`, `larakube up`
should ensure the commons are already running (same as it does for cloud envs).
Currently this is also guarded. With this change it becomes:
- Commons not running → `larakube up` starts them automatically (same as cloud path)
- Commons not provisioned → error with hint to run `plex:init` first

### Resource impact on local

The `larakube-plex` namespace adds:
- MariaDB: ~256Mi memory
- Redis: ~64Mi
- SeaweedFS: ~128Mi
Total: ~450Mi — significant on a 8 GB dev machine. Not a concern.

The warning should mention this for developers with tight machines (16 GB
shared with Colima / OrbStack / Rancher Desktop).

---

## Effort estimate

**Medium.** The guard removal is surgical (a few conditionals). The `larakube up`
Plex path integration requires verifying the boot sequence handles the
`larakube-plex` namespace in the same way cloud envs do. Also need to verify
that `plex:leave local` correctly tears down only the app tenant (not the commons)
since the commons are shared even on local.

---

## When to build

Triggered by a concrete need — e.g. a contributor who wants to write
integration tests against multi-tenant flows. Not urgent for solo users.
