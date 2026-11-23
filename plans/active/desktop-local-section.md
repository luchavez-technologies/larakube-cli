# Plan: Desktop "Local" section

**Status:** Slice 1 built (2026-11-23), awaiting a live check. Slices 2–4 not started.

## Why

Dev companions (Adminer, phpMyAdmin, pgAdmin, RedisInsight, Mongo Express, Mailpit) run once, cluster-wide, in the `larakube-companions` namespace of the local cluster, and serve every project. Desktop shows them as a tab on each project page, so every project shows the same list and nothing says they are shared.

Other things that concern the local machine as a whole also have no home: which projects are running locally and what they use, whether `*.test` resolves and the Local CA is trusted, and the live-reload watchers.

A **Local** section in the sidebar (under Build, after Projects) gathers them. A project page keeps only what is about that project: its own connection details, with buttons into the companions.

## What exists already

- **Companions:**
  - `CompanionController::all()` lists them from the cluster, with their running image and the CLI's pin.
  - The `companion:*` commands (add, remove, start, stop, update, list).
  - `ProjectCompanions::credentials()` gives a project's host/user/password per companion (`companion:list --json`).
- **Local projects:**
  - `ProjectWorkloadStatus` + `LocalClusterState` (a 15-second sync) give each project's local state.
  - `ProjectWatcher::summary()` gives its live-reload state.
  - `projects.stop-all` / `projects.down-all` exist.
- **Health:**
  - `doctor` collects issues with fixes: container runtime, cluster reachable, Traefik, and the Local CA/DNS trust chain via `diagnoseTrustChain()`. It has no JSON output.
  - `LocalCluster::detect()` names the local engine (OrbStack, k3s, …).

## Slices

### Slice 1: the section, companions moved, health, local projects (built)

**CLI**

- `doctor --json`: `{success, issues: [{title, description, fix}]}`. Outside a project it runs the cluster-wide checks only, as today.

**Desktop**

1. **Route and page:** `GET /local` renders `local/index`. The sidebar gets **Local** under Build, after Projects.
2. **Health card:**
   - shows `doctor --json` (deferred, cached for 60 seconds, refreshable);
   - "All good" when there are no issues; otherwise each issue with its fix command, copyable;
   - names the local engine from `LocalCluster::detect()`.
3. **Local projects card:**
   - every registered project with a local state other than down/uninitialized: state pill, live-reload status, Open, project link;
   - Stop all / Down all.
4. **Companions card:** `LocalDevCompanionsCard` moves here unchanged in behaviour. Its "Matches project X" badges become "Used by shop, blog", computed from all local projects' database and cache drivers.
5. **Project page:**
   - the Dev Companions tab goes away, and `?tab=companions` falls back to Overview;
   - the local Overview gets a **Connect** card: this project's credentials for each running companion that fits its database/cache, an Open button for each, and "Manage companions in Local →";
   - with no companion installed, the card says which one fits and links to Local.
6. **Copy:** the Plex engine card's note ("shown on your project's page under Local Dev Companions") points at the project's Connect card.

**Tests:** `doctor --json`, the Local page props, the project page no longer serving the companions tab, and the Connect card data.

### Slice 2: companions that come connected

- **pgAdmin:** start with every local project's Postgres preloaded as a server: a `servers.json` from a ConfigMap (`PGADMIN_SERVER_JSON_FILE`), rebuilt on `up`, the same way phpMyAdmin's `PMA_HOSTS` is.
- **RedisInsight:** pre-add each project's Redis through its API, or its `RI_*` env presets if they cover several databases. Verify which first.
- **Adminer:** open links pre-filled with server, user and database.
- Then remove the "opens with no connection set up" notes.

### Slice 3: Mailpit and local networking

- **Mailpit:** message count and latest messages per project (Mailpit's API), plus clear inbox.
- **Share tunnels:** active `share` tunnels per project, with stop.
- **Port-forwards:** the ones `TunnelManager` tracks, with stop.

### Slice 4: Local data (needs its own design)

- Per-project local databases: dump to a file and restore from one.
- **Pull production data locally:** restore a production backup into the local database. This has to settle anonymization and table exclusions before anything is built, because it copies real customer data onto a laptop.

## Out of scope

- Cluster housekeeping (image disk use, pruning, builder image updates): later, once there is a clear need.
- Per-project RAM/CPU on the Local projects card: `ClusterMetrics` is per server today; add it with slice 3 if cheap.
