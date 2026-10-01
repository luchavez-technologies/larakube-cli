# Multi-Server & Multi-Environment Deployment Architecture in LaraKube Desktop

## Executive Summary
LaraKube allows developers to configure distinct environments (`staging`, `production`, `qa`, etc.) where each environment can point to different servers (e.g., `staging-vps` vs. `prod-cluster`), distinct domains (`staging.example.com` vs. `example.com`), and different backing topologies (Plex Commons vs. Standalone Pod vs. Cloud RDS).

This plan outlines the enhancements to LaraKube Desktop to clearly display target servers per environment in the **Infrastructure Matrix** and provide an **Environment Selector** in the **Put It Online / Deployment Stepper**.

---

## 1. Data Model & Backend Resolution
### Context Resolution
- `.larakube.local.json` stores per-environment cloud context bindings:
  ```json
  {
    "environments": {
      "staging": {
        "cloud": { "ip": "34.27.253.31", "context": "larakube-34.27.253.31" }
      },
      "production": {
        "cloud": { "ip": "104.24.1.2", "context": "larakube-104.24.1.2" }
      }
    }
  }
  ```
- `.larakube.json` stores per-environment overlay configurations (hosts, plex, managed services).

### Enhancements
1. **Server Name Enrichment (`ProjectController.php`)**:
   - For each environment in `$inspection['environments']`, match `serverIp` or `serverContext` against ready servers from `StackCatalog` and assign `serverName: ?string` (e.g. `'gcp-test-vps'`).
2. **TypeScript Types (`larakube.ts`)**:
   - Update `ProjectEnvironment` to include `serverName?: string | null`.

---

## 2. Infrastructure Matrix Enhancement (`show.tsx`)
In `InfrastructureMatrixCard`:
- The **Environment** column provides explicit server identification:
  - For `local`: Badge `LOCAL` + `Localhost / K3d`.
  - For cloud environment with mapped server: Badge (e.g. `STAGING`, `PRODUCTION`) + clickable link to server: `[serverName](file:///servers/{serverName}) · {serverIp}`.
  - For cloud environment without mapped server: Badge + `No server linked` indicator.

---

## 3. "Put It Online" Deployment Stepper (`show.tsx`)
In `DeploySection`:
1. **Environment Segmented Control / Tabs**:
   - Displays all cloud environments (`staging`, `production`, etc.).
   - Includes a `+ Add Environment` button that triggers a simple inline form:
     - Environment name input (e.g., `staging`, `qa`).
     - Server select (from `readyServers`).
     - Creates the environment overlay via `POST /projects/{id}/link`.
2. **Active Environment Context**:
   - `activeEnv` state tracks the currently selected environment.
   - **Step 2 (Target Server)**:
     - Shows the linked server for `activeEnv`.
     - Provides a "Switch Server" button to change which server `activeEnv` targets.
     - If no server is linked, presents `LinkServerForm` passing `environment: activeEnv`.
   - **Step 3 (Address)**:
     - `HostForm` pre-fills with `currentEnv.webHost`.
     - Submits with `environment: activeEnv` to configure that environment's hostname.
   - **Step 4 (Deploy)**:
     - Button says `Deploy to ${activeEnv}`.
     - Submits with `environment: activeEnv`.
     - Status pills and terminal feedback reflect the targeted environment.

---

## 4. Verification & Testing
- Unit & Feature tests in `desktop/tests/Feature/ProjectsTest.php`:
  - Verify linking a server to a custom environment (e.g. `staging`).
  - Verify configuring host for a custom environment.
  - Verify deploying to a custom environment.
- Run `npm run types:check` and `npm run build`.
- Run PHPStan (`phpstan analyse --memory-limit=1G`), Pint (`pint --test`), and full Pest test suite (`./vendor/bin/pest`).
