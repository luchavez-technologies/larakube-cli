# Plan: Plex in Air-Gapped (Bundle) Environments

## Context

Plex ("commons") infrastructure — shared MariaDB/Redis/SeaweedFS/Meilisearch in
`larakube-plex` — currently only works in cloud environments (VPS + managed K8s).

Air-gapped bundles (`bundle:build` + `bundle:install`) deploy a fully self-contained
app stack. But they include their **own** database/cache/storage per bundle —
they do not support shared Plex commons across multiple bundles on the same server.

**The use case for Plex + airgap:**
An enterprise customer hosts multiple apps from the same vendor on a single on-prem
server. Instead of each bundle running its own MariaDB, they want a shared database
cluster for DBA access, backup automation, and resource efficiency.

**Status:** Deferred. No concrete customer need yet. Design is non-trivial.

---

## Design (draft — not final)

### The "Commons bundle" concept

A "Commons bundle" is a separate `bundle:build commons` artifact that installs
only the `larakube-plex` namespace (MariaDB, Redis, SeaweedFS, Meilisearch)
without any app. App bundles are then installed as **tenants** against this commons.

```bash
# On the enterprise server:
sudo ./larakube bundle:install --commons-only   # installs larakube-plex only
sudo ./app1/larakube bundle:install --plex      # app1 joins the commons
sudo ./app2/larakube bundle:install --plex      # app2 joins the same commons
```

The `--plex` flag on `bundle:install` suppresses per-app database/cache/storage
deployment and injects the commons connection strings instead of generating
per-app credentials.

### Key design challenges

1. **Image overlap**: Both app bundles and the commons bundle would include the
   MariaDB image (it's in each app's `images/` folder currently). Need a way
   for `bundle:install --plex` to skip images already loaded, or to centralise
   image import in the commons bundle step.
   → `k3s ctr images list` + digest check before import handles this.

2. **Credentials bootstrap**: The commons bundle generates a root DB password
   at install time. App bundle installs need to discover that password and
   create their tenant DB + user. Options:
   - `--commons-root-password` flag on `bundle:install --plex` (user copies it
     from commons install summary)
   - Shared secret file at a known path (`/opt/larakube-commons/.root-creds`)

3. **Version alignment**: The commons MariaDB image version must match what app
   bundles expect. If App A was built with MariaDB 11.2 and App B with 10.6,
   and the commons runs 11.2, App B may have issues.
   → LaraKube CLI should enforce a `commons.mariadb_version` in a shared manifest
   and warn/block if an app bundle was built against a different version.

4. **Multiple commons** (stretch goal): If two vendors each have a commons bundle
   on the same server, they'd need different namespace names to avoid collision
   (`larakube-plex-vendor1`, `larakube-plex-vendor2`). Requires namespaced
   commons — added complexity.

---

## Effort estimate

**High.** Requires:
- New `bundle:build commons` command
- `bundle:install --commons-only` path
- `bundle:install --plex` path (tenant join logic in offline context)
- Credential sharing mechanism
- Image deduplication on import
- Version alignment validation

---

## When to build

**Wait for a concrete customer need.** The feature requires a customer with:
1. Air-gapped deployment requirement
2. Multiple apps from the same vendor
3. A reason to share infrastructure (DBA access, backup strategy, license cost)

Until that customer exists, the design above is sufficient as a reference. Do not
start implementation speculatively — the commons bundle design touches many core
bundle abstractions and could introduce regressions if built without a test subject.
