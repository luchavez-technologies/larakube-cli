# Environment-First Project Details Redesign

## Context & Motivation
The previous Project Details page suffered from vertical bloat and visual claustrophobia: stacking the local cluster card, the multi-environment infrastructure matrix table, the live terminal output card, the full 4-step "Put It Online" stepper card, and right-hand sidebars all on a single scrollable view.

This redesign introduces an **Environment-First Navigation Model**:
1. A prominent top-level **Environment Switcher** with a distinctive **Local** development view and individual **Cloud Environment** tabs (`Staging`, `Production`, etc.).
2. **Context-Segregated Content**:
   - In **Local** mode: Only local container status (`up`/`down`/`start`/`stop`), local Plex Commons services, and local terminal activities are shown.
   - In **Cloud** mode: Shows the target server (`staging-vps`), public domain, environment-specific backing topology, and cloud deployment runs for that specific environment.
3. **"Put It Online" as a Pop-up Dialog**:
   - Replaces the 400px inline stepper card with a clean modal dialog accessible via a prominent **"Deploy"** / **"Configure Deployment"** button.

---

## Architecture & Layout Plan

### 1. Top Environment Switcher
Below the page header:
- Segmented environment tabs:
  - **`💻 Local`**: Highlighted tab for daily development on local machine / K3d.
  - **`☁️ Staging`**, **`☁️ Production`**, etc.: Individual cloud environment tabs.
  - **`+ Add Environment`**: Lightweight trigger to bind a new environment overlay to a server.
- Contextual Header Actions:
  - When on `Local`: "Open Local Site" (`http://<name>.<tld>`), Local lifecycle buttons.
  - When on Cloud: "Deploy" button (opens the Deploy Pop-up), "Open Site" (`https://<domain>`).

### 2. Segregated Views
#### Local View (`activeEnv === 'local'`)
- **Local Cluster Card**: Container status, local domain with TLD switcher, lifecycle verbs (`up`, `down`, `start`, `stop`).
- **Local Backing Services Card**: Displays SQLite / Local Postgres, Redis, MinIO, and Plex Commons membership with 1-click Join/Leave.
- **Local Terminal & Activity**: Filtered strictly to local runs (`up`, `down`, `init-project`).

#### Cloud Environment View (`activeEnv !== 'local'`)
- **Target Server & Deployment Card**:
  - Displays linked server (`staging-vps · 34.27.253.31`) with link to server details and "Change Server" action.
  - Displays public web host with DNS helper and edit capability.
  - Displays deployment status (`Deployed` or `Not deployed`).
  - "Deploy to <Environment>" primary CTA.
- **Environment Backing Services Card**:
  - Displays backing services topology for this environment (Plex Commons vs Standalone Pod vs Cloud Managed RDS/S3).
  - 1-click Join/Leave Commons for this environment.
- **Environment Terminal & Activity**:
  - Filtered strictly to runs targeting this environment (`deploy-app`, `configure-host`, `link-server`).

### 3. Deploy Dialog (`DeployDialog`)
- Modal popup opened via:
  - "Deploy" or "Configure Deployment" button.
- Guides through server selection, web host configuration, and trigger deploy.
- Cleanly closes without cluttering the main layout.

---

## Verification & Status
- [x] TypeScript check passing: `npm run types:check` (0 errors)
- [x] Vite production build passing: `npm run build` (6.01s)
- [x] Backend static analysis passing: `phpstan analyse` (0 errors)
- [x] Code style formatting passing: `pint --test` (0 issues)
- [x] Backend Pest test suite passing: `123 passed`, `802 assertions`
- [x] UI implementation complete: Environment switcher, segregated local/cloud views, backing services card with 1-click Plex Commons toggle, and modal deployment popup.
- [x] Environment-aware runs & terminal output:
  - Backend `ProjectController::run()` now tags `meta.environment` on all new runs.
  - Historical runs fallback to inferring environment from label or command kind.
  - `RecentRunsCard` now dynamically filters by the active environment (e.g. `Recent runs · PRODUCTION` vs `Recent runs · LOCAL`) with a "Show all" / "Filter" toggle.
  - `ProjectTerminalCard` switches output to match the active environment, while remaining sticky if a command is actively running.

