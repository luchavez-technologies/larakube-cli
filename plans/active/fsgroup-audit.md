# fsGroup audit — other Cluster Tools at risk of the n8n PVC permission bug

## Context

`n8n:init` got stuck on a GKE cluster (`hello-gke-test`) with
`EACCES: permission denied, open '/home/node/.n8n/config'`. Root cause,
confirmed and fixed (`bd4bcea4`, `resources/views/k8s/n8n/shared.blade.php`):
n8n's image runs as the unprivileged `node` user (UID/GID 1000), but the
Deployment never set `securityContext.fsGroup` — a freshly provisioned PVC
defaults to root-owned on most CSI drivers, so the non-root process can't
write to it. `fsGroup` is a core, cluster-agnostic Kubernetes field (not
GKE-specific) — it's purely additive (grants group-write access, never
restricts), so applying it anywhere it's missing is safe, including on
already-working VPS/k3s installs.

**This is a systemic pattern, not a one-off.** Any Cluster Tool whose image
(a) runs as a non-root user by default and (b) doesn't handle volume
ownership itself (via its own root-then-drop-privileges entrypoint, the way
official Postgres/MySQL/MariaDB/MongoDB/WordPress images already do) hits
the identical failure the moment it lands on a PVC with stricter default
ownership than whatever CSI driver it happened to be tested on. The
correct, already-established fix pattern exists in this codebase today —
`resources/views/k8s/stalwart/stalwart.blade.php:77-80` sets
`fsGroup: 2000` for Stalwart's non-root UID. n8n just never got it. This
plan is a systematic audit for every other tool that might be missing it
too.

## Audit findings so far (confidence-tiered — verify before fixing, don't
just copy the UIDs below blind)

### Tier 1 — confirmed non-root image, confirmed needs fsGroup, fix first

- **The entire `monitor:init` stack** (`resources/views/k8s/grafana/shared.blade.php`
  — one file, multiple Deployments: Prometheus, Loki, Tempo, Promtail,
  kube-state-metrics, Grafana). **Zero `securityContext` anywhere in this
  file.** Highest priority — `monitor:init` is far more widely deployed
  than n8n.
  - Grafana (`grafana/grafana:10.4.2`): **UID/GID 472, sourced** — a
    well-documented, extremely common Kubernetes gotcha for this exact
    image.
  - Prometheus (`prom/prometheus:v2.51.2`): non-root by default (commonly
    UID 65534 / `nobody`) — verify the exact UID against the pinned
    v2.51.2 image before applying, don't assume.
  - Loki (`grafana/loki:3.0.0`): non-root by Grafana Labs' own convention
    (commonly UID 10001 across their images) — verify against the pinned
    version.
  - Tempo (`grafana/tempo:2.10.7`): same family/convention as Loki —
    verify independently, don't assume it matches Loki's UID without
    checking.
  - Promtail (`grafana/promtail:3.0.0`): **different risk category** — it
    needs to read host log files, which may mean a `hostPath` mount or
    elevated access rather than a plain PVC ownership problem. Investigate
    separately; don't fold it into the same blind fsGroup fix as the
    others.
  - kube-state-metrics: stateless, no PVC — **not at risk**, exclude.
- **Forgejo** (`resources/views/k8s/forgejo/forgejo.blade.php`): **UID/GID
  1000, sourced** (Forgejo's own Docker docs require matching volume
  ownership). Note: the file already has a `securityContext` block on its
  bundled `quay.io/podman/stable` runner sidecar (line 469) — easy to
  mistake that for full coverage in review. The main `forgejo` container
  itself (image at line 91, mounts at line 299) has none.
- **Matrix Synapse** (`resources/views/k8s/matrix/matrix.blade.php`):
  **UID/GID 991, sourced** — a specifically well-documented, long-standing
  permission gotcha for this exact image (Synapse's own image docs state
  the 991 default explicitly).

### Tier 2 — confirmed non-root, exact UID needs one more check first

- **OpenBao** (`resources/views/k8s/openbao/openbao.blade.php`):
  Vault-family images run non-root by convention; found a third-party
  derivative using UID 1102, but that's not confirmed to be upstream's own
  default. Check the actual `openbao/openbao` image's Dockerfile `USER`
  line (or `docker inspect`) before picking a number.

### Tier 3 — confirmed NOT at risk, don't "fix" these

- **Vaultwarden**: the project's own Hardening Guide explicitly states the
  official image runs as **root by default**, specifically so bind-mounted
  volumes work without permission issues. No fsGroup needed — if anyone
  "fixes" this later for consistency with the others, make sure it doesn't
  break what currently works by accident of running as root.
- **Uptime Kuma**: reasonably inferred to run as root by default (a
  separately-named `-rootless` tag exists, implying the standard
  `louislam/uptime-kuma:1` tag this project pins doesn't default that way)
  — less firmly sourced than Vaultwarden, worth a quick confirm rather
  than a blind skip, but not a priority.
- **PocketBase** (`ghcr.io/muchobien/pocketbase`): **confirmed, sourced
  directly from the image's own `Dockerfile` and `entrypoint.sh`** — no
  `USER` instruction anywhere, and the entrypoint never switches users
  (no `su-exec`/`gosu`/`setpriv`), so it runs as root unconditionally. This
  is *why* `pocketbase:init` deployed fine on the same GKE cluster where
  `n8n:init` failed — not a contradiction of the fsGroup diagnosis, the
  predicted other half of it. n8n's image is deliberately hardened to a
  non-root user (good security practice); PocketBase's community-maintained
  image just isn't hardened at all. Whether a tool is exposed to this bug
  tracks exactly with whether its image bothers to drop root, nothing else
  — worth keeping in mind when triaging the still-unverified Tier 4 list
  below: a "did it deploy fine elsewhere" report isn't by itself evidence a
  tool is safe, since it depends entirely on that specific image's own
  hardening choice.

### Tier 4 — not yet investigated, same audit method applies

Every other tool that mounts a PVC without `fsGroup` today (full list
pulled via `grep -rl "PersistentVolumeClaim\|volumeMounts" resources/views/k8s/`
minus `fsGroup` minus app-infra patches that inherit `securityContext` from
`base/deployment.blade.php`, which already has it): GlitchTip, Metabase,
oCIS, PocketBase, Documenso, Bulwark, Netbird, Windmill, LiveKit, WordPress,
Meilisearch, Typesense, SeaweedFS, MinIO, Garage, `system-dashboard`
(Headlamp).

- **MinIO specifically**: research was ambiguous — official-image examples
  show no `securityContext`, but common derivatives (Bitnami, the MinIO
  Operator's own tenant chart) default non-root (UID 1000-1001). Check
  which exact image `StorageDriver::MINIO` actually pins
  (`app/Enums/StorageDriver.php`) before concluding either way — don't
  assume it matches a derivative's convention.
- **WordPress**: likely safe (the official `wordpress:*-apache` image
  follows the same root-then-drop-privileges entrypoint pattern as
  official DB images), but not independently confirmed here — verify
  rather than assume given its init container (`busybox:1.36`) is already
  doing its own setup work.

## Audit method (repeat per Tier 4 tool)

1. Find the tool's pinned image + exact version (vendor class or Blade
   file — never guess a version per this repo's own vendoring-diligence
   rule in `CLAUDE.md`).
2. Check that image's own Dockerfile (GitHub source, or
   `docker inspect <image>` / `docker run <image> id` directly) for a
   `USER` directive. No `USER` line, or `USER root` / `USER 0` → runs root,
   not at risk, done.
3. If non-root: check whether the entrypoint does its own
   root-then-drop-privileges `chown` on the data directory (the pattern
   official Postgres/MySQL/MariaDB/MongoDB/WordPress already use). If so,
   no fsGroup needed — the image already handles it; document why, same as
   Tier 3 above, so it isn't "fixed" unnecessarily later.
4. If non-root with no internal chown step: add
   `securityContext: fsGroup: <UID>` at the pod template level, following
   the exact pattern already in
   `resources/views/k8s/stalwart/stalwart.blade.php:77-80` (comment
   explaining which user/UID it's for, same as the n8n fix).
5. Add a regression test asserting the rendered manifest contains the
   right `fsGroup` line — follow the pattern just added to
   `tests/Unit/FlowEngineTemplatesTest.php` for n8n (static content
   assertion, cluster-agnostic, no live cluster needed for this part).
6. **Confirm live**, not just via the template-content test — this bug
   class only actually manifests at pod-startup time on a real PVC. Test
   on at least one strict CSI driver (GKE, same as how the n8n bug was
   actually caught) and confirm the existing VPS/k3s target still works
   unchanged, before calling a given tool's fix done.

## Out of scope

- Tools that don't mount a PVC at all (stateless) — not applicable to this
  bug class, no need to touch them.
- Promtail's hostPath/log-reading access model — a different risk
  category from plain PVC ownership; needs its own investigation, not a
  blind fsGroup copy-paste.
- Any tool not yet confirmed non-root — don't add `fsGroup` speculatively;
  an unnecessary `fsGroup` on an already-root image is harmless but adds
  noise and a false signal that someone already verified it.

## Critical files

- `resources/views/k8s/grafana/shared.blade.php` — highest priority, 5
  stateful containers, zero coverage today.
- `resources/views/k8s/forgejo/forgejo.blade.php` — main container missing
  it; sidecar already has it, don't let that mask the gap in review.
- `resources/views/k8s/matrix/matrix.blade.php` — Synapse, UID 991.
- `resources/views/k8s/openbao/openbao.blade.php` — needs the one UID
  confirmation step before fixing.
- `resources/views/k8s/stalwart/stalwart.blade.php:77-80` — the reference
  pattern every fix here should match.
- `resources/views/k8s/n8n/shared.blade.php` + `tests/Unit/FlowEngineTemplatesTest.php`
  — the just-shipped fix and its test, as the template to replicate.
- `app/Enums/StorageDriver.php` — confirm MinIO's exact pinned image
  before deciding its tier.

## Verification

- `composer format && composer analyse && composer test` (this repo's
  standard gate) after each tool's fix.
- Live-cluster confirmation on a GKE target (or equivalent strict CSI
  driver) for each tool fixed, plus a sanity check that an existing
  VPS/k3s deployment of the same tool is unaffected — matching exactly how
  the n8n bug was actually caught and confirmed fixed.
