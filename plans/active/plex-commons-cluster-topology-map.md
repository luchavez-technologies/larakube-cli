# Implementation Plan: Plex Commons Cluster-Wide Topology Map

## 1. Goal Description
Implement an interactive, educational, and operational **Plex Commons Cluster Topology Map** in LaraKube Desktop.

Using `@xyflow/react` (v12 for React 19) paired with `motion`, students, developers, and operators can view an interactive node diagram of their entire local cluster topology:
- **Applications Tier (Left)**: All registered projects (e.g. `cloud`, `api`, `docs`), their framework badges, container run states, and local domains.
- **Backing Engines Tier (Right)**: Active Plex Commons shared services (`PostgreSQL 16`, `MySQL 8`, `Redis 7`, `MinIO S3`, `Mailpit`, `Meilisearch`) with their PVC storage allocations, live RAM gauges, and healthy pod status.
- **Animated Conduits**: Glowing, animated Bezier conduits connecting each application to its assigned Plex engines (e.g., `cloud` ➔ `PostgreSQL` via `cloud_db:5432` and `Redis:6379`), while static sites (like `docs`) stand independent without database links.
- **Interactive Inspection**: Clicking any app, engine, or conduit reveals connection secrets, internal Kubernetes DNS endpoints, injected `.env` keys, and volume block storage details.

---

## 2. Location & Navigation
- In [`/plex`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/plex/index.tsx), introduce a clean view toggle right beside the section title:
  - `[ ▦ Services Deck ]` (Grid view with engine cards and tenant hub)
  - `[ 🌐 Topology Map ]` (Interactive node canvas)
- In the project's "Backing Services" card (`show.tsx`), add a quick link: `"View in Cluster Topology Map"`.

---

## 3. Data Requirements
1. **Controller (`PlexController.php`)**:
   - Query registered projects with `ProjectInspector` to resolve frameworks, local domains, database drivers, and cache drivers.
   - Pass `'projects' => $projects` into the Inertia payload for `plex/index`.
2. **Plex Commons Report**:
   - Already provides `services`, `serviceCatalog`, `tenants`, and `podMetrics`!

---

## 4. Component Structure
- `desktop/resources/js/pages/plex/components/topology/`:
  - `plex-topology-canvas.tsx`: Main React Flow canvas with mini-map, controls, and background grid.
  - `app-node.tsx`: Custom React Flow node for registered client/tenant applications.
  - `engine-node.tsx`: Custom React Flow node for Plex Commons shared infrastructure engines (Postgres, Redis, MinIO, etc.).
  - `conduit-edge.tsx`: Custom animated Bezier edge showing live database/cache sockets.
  - `topology-inspector.tsx`: Floating or side drawer displaying connection strings, `.env` blocks, and PVC storage status when a node or edge is selected.

---

## 5. Verification Plan
- `npm run check:fix`
- `npm run types:check`
- `composer lint:check`
- `composer types:check`
- Manual inspection in `composer native:dev`.
- Strictly **NO `./build`** and **NO `git commit`** until user approval.
