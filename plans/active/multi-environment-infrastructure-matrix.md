# Plan: Multi-Environment Backing Infrastructure Matrix (Pattern 2)

## Problem Statement
Currently, the Project Details page presents Plex Commons as a single project-level card. This creates confusion for multi-environment setups:
- In reality, Plex Commons is **per-cluster**, not a global monolith.
- A project might use Local Plex Commons for `local`, the VPS cluster's Plex Commons for `staging`, but an external AWS RDS + S3 provider for `production`.
- If an environment uses AWS RDS, displaying "Not joined to Plex Commons" falsely signals a broken configuration.
- Furthermore, as teams scale to 4-6 environments (`local`, `dev`, `qa`, `uat`, `staging`, `production`), the UI must remain height-budgeted (~160-200px) and avoid pushing down other content.

## Architectural Model
In `.larakube.json`:
- `managed`: Services where LaraKube skips deploying isolated pods because an external or shared provider handles them.
- `plex`: A specialization of `managed` specifically indicating the service is backed by the cluster's shared Plex Commons (`larakube-commons`).

Backing status for any service (Database, Cache, Storage) resolves to:
1. **🟣 Plex Commons**: In `plex` array (shared multi-tenant engine on this cluster).
2. **☁️ Cloud Managed**: In `managed` array but not `plex` (external AWS RDS, ElastiCache, S3, Cloudflare R2).
3. **📦 In-Cluster Pod**: Not in `managed` or `plex` (dedicated StatefulSet/pod in the project namespace).
4. **📁 Local Disk / File**: SQLite database or local filesystem disk.

## Design: Compact Micro-Row Matrix
We will transform the Plex Commons card into a comprehensive **Infrastructure & Backing Services** card:
1. **Header**: "Infrastructure & Backing Services" with an environment counter badge (e.g. `3 Environments`).
2. **Table Grid**:
   - Compact table with columns: `Environment`, `Database`, `Cache & Queues`, `Storage`, and `Actions`.
   - Each row is tight (~36px height) with sleek badge chips:
     - 🟣 `Plex Postgres` / `Plex Redis` / `Plex MinIO`
     - ☁️ `AWS RDS (Cloud)` / `S3 (Cloud)`
     - 📦 `Isolated Pod`
     - 📁 `Local Disk` / `SQLite`
   - Fixed height budget: wrapped in `max-h-60 overflow-y-auto custom-scrollbar-dark` so whether there is 1 environment or 10 environments, it never dominates the screen.
3. **Contextual Actions**:
   - Environment-specific actions: e.g. for `local`, 1-click `Join Plex` or `Leave`.
   - Clear visual status of whether external credentials or cluster tenant are active.

## Implementation Steps
1. **Backend (`desktop/app/Services/LaraKube/ProjectInspector.php`)**:
   - Expose `managed: list<string>` on each environment object in `$environments`.
   - Expose `database`, `cacheDriver`, `objectStorage` on the project object.
2. **Types (`desktop/resources/js/types/larakube.ts`)**:
   - Update `ProjectEnvironment` to include `managed: string[]`.
   - Update `Project` to include `database?: string | null`, `cacheDriver?: string | null`, `objectStorage?: string | null`.
3. **Frontend Component (`desktop/resources/js/pages/projects/show.tsx`)**:
   - Replace the legacy `PlexMembershipCard` with the new `InfrastructureMatrixCard`.
   - Render the micro-row matrix with status badges for Database, Cache, and Storage.
   - Include height capping (`max-h-64 overflow-y-auto`) and 1-click Plex join/leave actions keyed to the target environment.
4. **Automated Testing & Verification**:
   - Run `npm run types:check`, `npm run build`, and `./vendor/bin/pest`.
   - Run `composer analyse` and `composer test` on CLI.
