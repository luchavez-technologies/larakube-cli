# Plan: Reuse Plex Commons storage for the monitoring stack

> **Status:** §4.2 (Grafana → Plex database) shipped 2026-08-18 — see that section for
> why the original premise changed. §4.1 (Loki → Plex object storage) and §4.3
> (Prometheus, explicitly out) are still queued.

## 1. Question this answers

Should Grafana / Loki / Prometheus reuse the Plex Commons object store (the MinIO we
planned for Gitea), or Plex database / Plex redis? Answer, per component below — only
**Loki → Plex object storage** is a clean, idiomatic fit. The rest are "no" or
"only under HA".

## 2. Per-component analysis

| Component | Object storage | Database | Redis |
| :--- | :--- | :--- | :--- |
| **Loki** | **Yes — native S3 chunk/index store.** Currently on a filesystem PVC (`loki-storage`). The strongest fit. | No | No (optional query cache only) |
| **Grafana** | No | **Optional** — SQLite-on-PVC by default; external Postgres/MySQL **only** to run multiple replicas (HA needs a shared DB). | No |
| **Prometheus** | **No** — vanilla Prometheus is a local-PVC TSDB. Object storage means adding **Thanos / Mimir**, a much larger architecture. | No | No |

Redis: **none** of the three need it. Skip entirely.

## 3. The tier-coupling constraint (the important part)

Recorded architecture (`project_monitoring_tier` memory): monitoring is **cluster-base
infra for all envs** — `monitor:init` is independent, deliberately **NOT** a Plex
Commons member ("coupling is inverted"). Plex Commons (where the shared MinIO lives) is
the **optional, opt-in** tier.

⇒ If Loki *requires* Plex MinIO, base infra now depends on Plex being initialised first,
which **inverts the dependency the architecture forbids**. `monitor:init` would no
longer stand alone.

**Therefore: auto-detect, never hard-wire.**
- Loki **defaults to its filesystem PVC** (monitoring stays self-contained, Plex-free).
- **When a Plex Commons with object storage is present**, optionally point Loki's chunk
  store at it (reusing the same S3 endpoint/bucket/creds Gitea will use).
- `monitor:init` must still fully succeed with **no** Plex Commons on the cluster.

## 4. Proposed scope

### 4.1 Loki → Plex object storage (opt-in, auto-detected) — the main item
- Detect a Plex Commons S3 backend on the target cluster (reuse the Plex spec/registry
  read path — `getCommonsSpec()` / the S3 service in the Commons services map).
- When present (and not explicitly opted out), render Loki's `storage_config` for S3
  (endpoint, bucket, access/secret) instead of `filesystem`; drop the `loki-storage` PVC
  in that mode.
- A dedicated bucket (e.g. `loki`) provisioned in the Commons store, mirroring how Plex
  allocates per-tenant buckets — but this is **infra-owned**, not a tenant.
- Flag(s): `monitor:init --loki-storage=plex|pvc` (default: auto = plex-if-present, else
  pvc). `--remove` must clean up whichever backend was used (don't delete a PVC that was
  never created; don't orphan the bucket).
- Idempotent re-run must not flip backends silently — record the chosen backend so a
  later `monitor:init` keeps it (switching backends loses historical logs).

### 4.2 Grafana → Plex database — ✅ SHIPPED 2026-08-18, for a different reason than planned
This section originally deferred Grafana-on-Postgres until HA. That premise turned out
to be wrong: `monitor:init` never persisted Grafana's own database *at all* — no PVC,
no external DB, just the built-in SQLite on the pod's ephemeral filesystem. A teammate's
UI-created dashboards were lost this way (confirmed live 2026-08-18) — not an HA problem,
a **data-loss** problem that existed even at replicas=1.

What shipped, keeping §3's constraint intact (monitor:init must still fully succeed with
no Plex Commons on the cluster):
- **Default:** a real Commons Postgres tenant (`grafana` database), OpenBao static-role
  password rotation, same pattern every other Commons-backed tool uses — rides along
  with the existing nightly Commons backup for free.
- **`--no-plex`:** falls back to SQLite on a `grafana-storage` PVC (1Gi) instead of the
  pod's ephemeral filesystem — still genuinely persistent, just not Commons-backed or
  covered by the nightly backup. Mirrors `git:init`'s own `--no-plex` story.
- `MonitorTool` gained `HasCommonsDatabases`/`HasDbSecretRef`/`HasRotatableDatabasePassword`/
  `HasOpenbaoSync`; `MonitorRemoveCommand::usesBundledStorage()` detects the PVC to skip
  a `--purge` Commons-drop attempt on `--no-plex` installs.
- HA Grafana (multiple replicas) still isn't built — nothing here blocks it; a shared DB
  was always a prerequisite either way, and it's now already in place by default.

### 4.3 Prometheus — explicitly out
- No object storage without Thanos/Mimir. Track that as its own (much larger) plan if
  long-term/remote metric storage is ever wanted. Not here.

## 5. Out of scope
- Thanos / Mimir (Prometheus long-term storage).
- Any Redis usage for monitoring.
- Making Plex a prerequisite for `monitor:init` (the whole point of §3 is the opposite).

## 6. Sequencing
- Lands **after** the Plex shared object store is real for Gitea (1.x), since this reuses
  exactly that backend. Until then there's no shared MinIO to point Loki at.
- Ship v0.21.x (current monitoring stack on PVCs) first; this is a later enhancement.

## 7. Done when
- `monitor:init` on a cluster **without** Plex → Loki on PVC, unchanged behaviour.
- `monitor:init` on a cluster **with** a Plex Commons S3 backend → Loki chunks/index in
  the Commons bucket, no `loki-storage` PVC; logs queryable in Grafana.
- `--remove` cleans up the correct backend in both modes.
- Pest coverage for the storage-backend selection logic (Plex-present vs absent vs
  explicit flag), pure/no-cluster like the existing monitoring/credential tests.
