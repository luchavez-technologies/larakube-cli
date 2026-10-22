# Implementation Plan: Universal Environment Variables, Multi-Pod Redeployment, `app-secrets` Naming & OpenBao Integration

Overhaul LaraKube's environment variable architecture across CLI and Desktop:
1. **Interactive Environment Variable Manager**: Enable viewing, adding, editing, and deleting environment variables across all environments (Local, Staging, Production).
2. **Vercel/Netlify-Style Multi-Pod Redeployment**: When updating variables in non-local environments (like Production), automatically save to disk, push to the cluster (OpenBao / Kubernetes), and trigger a zero-downtime rolling restart across **all application pods** (`web`, `horizon`, `queues`, `reverb`, `ssr`).
3. **Framework-Neutral Secret Naming (`app-secrets` / `app-config`)**: Graduate from legacy `laravel-secrets` and `laravel-config` to universal `app-secrets` and `app-config` (with seamless backwards-compatible fallback for existing deployments).
4. **Effortless OpenBao Integration**: Demystify OpenBao with inline vault badges, automatic vault synchronization, rotation badges, and one-click vault UI access.
5. **First-Class SPA `.env` Parity**: Full `.env` support for Astro, Vite, Next.js, and static SPAs (e.g. Astro connecting to PocketBase via `PUBLIC_POCKETBASE_URL`).
6. **Show Project UI Cleanup**: Remove the redundant terminal card from the project show page.

---

## User Review Required

> [!IMPORTANT]
> **1. Multi-Pod Rolling Redeployment (Handling Web, Horizon, Queues, Reverb, SSR, Scheduler)**:
> In Laravel (and other complex stacks), an application does not consist of just one pod. It may run:
> - **`web`**: Main HTTP application server (FrankenPHP / PHP-FPM / Caddy).
> - **`horizon`**: Redis queue supervisor daemon.
> - **`queues`**: Standard worker pods.
> - **`reverb`**: Real-time WebSockets daemon.
> - **`ssr`**: Node.js Server-Side Rendering bundle daemon.
> - **`scheduler`**: Kubernetes `CronJob` running every 60 seconds (`* * * * *`).
>
> **How LaraKube Handles Redeployment for All of Them:**
> - When "Save & Redeploy" runs, LaraKube executes:
>   ```bash
>   kubectl rollout restart deployment -l 'larakube-preview!=true,larakube.dev/role!=share' -n <namespace>
>   ```
> - **Deployments (`web`, `horizon`, `queues`, `reverb`, `ssr`)**: Kubernetes issues a parallel, zero-downtime rolling restart. For each deployment, a new pod is started with the updated ConfigMap/Secret. Only once the new pod passes its readiness probe is the old pod gracefully terminated. No incoming web requests or WebSocket connections are dropped.
> - **Scheduler (`CronJob`)**: A CronJob is not a long-running Deployment. It spins up a fresh container every 60 seconds. Because it reads from `app-secrets` and `app-config`, the scheduler **automatically picks up the updated environment variables on its next execution within 60 seconds** without needing manual intervention.

> [!IMPORTANT]
> **2. Which Environment Variables Go to OpenBao vs ConfigMap?**:
> **Not all environment variables are pushed to OpenBao.** Storing plain configuration in Vault is wasteful and complicates auditing. LaraKube splits variables into two categories:
> - **Secret Runtime Credentials (Pushed to OpenBao / `app-secrets`)**:
>   - Detected automatically via `isSecretKey()`: Keys containing `KEY`, `SECRET`, `PASSWORD`, `TOKEN` (e.g. `APP_KEY`, `DB_PASSWORD`, `STRIPE_SECRET`, `AWS_SECRET_ACCESS_KEY`), or connection strings carrying credentials (like `DATABASE_URL=postgres://user:pass@host/db`).
>   - If OpenBao is active: Pushed to OpenBao Vault (`secret/data/{environment}/{app}/{key}`). The External Secrets Operator (ESO) automatically syncs them into the namespace as the Kubernetes Secret `app-secrets`.
>   - If OpenBao is not active: Written directly to Kubernetes Secret `app-secrets`.
>   - **Precedence Rule:** Credentials rotated by OpenBao (like automated database password rotation) are protected from being accidentally overwritten by stale local values.
> - **Public Configuration (Stored in `app-config` ConfigMap or Baked into JS)**:
>   - Static configuration like `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `LOG_CHANNEL`, `CACHE_STORE`, `PUBLIC_POCKETBASE_URL`, `VITE_API_URL`.
>   - Stored in the plain Kubernetes ConfigMap `app-config` (or compiled into client bundles for Astro/Vite).

> [!IMPORTANT]
> **3. Canonical Naming: `app-secrets` & `app-config` with Backwards Compatibility**:
> - Replaces `laravel-secrets` and `laravel-config` with universal names suitable for all 15 supported frameworks.
> - **Fallback**: If a cluster already has `laravel-secrets` / `laravel-config`, LaraKube detects and reads them seamlessly, ensuring existing deployments remain 100% operational.

> [!IMPORTANT]
> **4. Terminal UI Removal**:
> - The bottom `ProjectTerminalCard` (`activeTerminalRun`) will be removed from `show.tsx`.

---

## Architectural Flow: Environment Variable Lifecycle & Multi-Pod Rollout

```mermaid
sequenceDiagram
    autonumber
    actor Dev as Developer / Operator (Desktop)
    participant UI as Environment Variables UI
    participant Backend as ProjectDotenvController
    participant Disk as Local .env.production
    participant CLI as LaraKube CLI (dotenv:push)
    participant Vault as OpenBao Vault / ESO
    participant K8s as Kubernetes Namespace
    participant Pods as Web, Horizon, Queues, Reverb, SSR

    Dev->>UI: Edit / Add Variables (e.g. STRIPE_KEY, APP_URL)
    Dev->>UI: Click "Save & Redeploy"
    UI->>Backend: POST /projects/{project}/dotenv/update
    Backend->>Disk: Write updated .env.production
    Backend->>CLI: Run dotenv:push production
    par Secrets to Vault
        CLI->>Vault: Write Secret Keys to OpenBao (secret/data/...)
        Vault->>K8s: ESO syncs to Secret 'app-secrets'
    and Public to ConfigMap
        CLI->>K8s: Write Public Keys to ConfigMap 'app-config'
    end
    Backend->>K8s: kubectl rollout restart deployment -l 'larakube-preview!=true,larakube.dev/role!=share' -n {namespace}
    par Zero-Downtime Rolling Restarts
        K8s->>Pods: Rolling restart 'web' (0 downtime)
        K8s->>Pods: Graceful restart 'horizon' (finish jobs)
        K8s->>Pods: Rolling restart 'queues'
        K8s->>Pods: Rolling restart 'reverb'
        K8s->>Pods: Rolling restart 'ssr'
    end
    Note over K8s,Pods: Scheduler CronJob picks up new env on next 60s tick
    Backend-->>UI: Success (all workloads rolled)
    UI-->>Dev: Toast: "Saved and all application pods redeployed!"
```

---

## Proposed Changes

### Component 1: Project Show UI & Terminal Cleanup (`desktop`)

#### [MODIFY] [`desktop/resources/js/pages/projects/show.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/projects/show.tsx)
- Remove `activeTerminalRun && <ProjectTerminalCard run={activeTerminalRun} />` from the main layout.
- Clean up unused `activeTerminalRun` useMemo and imports.

#### [MODIFY] [`desktop/resources/js/components/environment-secrets-card.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/components/environment-secrets-card.tsx)
Transform into an **Inline Environment Variables & Secrets Manager**:
- **Inline Variables Table**:
  - Displays all environment variables directly on the page (no need to open a modal).
  - Search/filter input to quickly find specific keys.
  - Column 1: Variable Name with `Lock` icon for secrets, `Globe` icon for public vars (`PUBLIC_*`, `VITE_*`).
  - Column 2: Status pill (`In Sync`, `Drifted`, `Local Only`, `Cluster Only`, `Rotated by Vault`).
  - Column 3: Local Value (with "Reveal / Mask" toggle).
  - Column 4: Cluster Value.
  - Column 5: Inline Edit & Delete buttons.
- **Add Variable Form**:
  - Quick inline input for `KEY` and `VALUE` with an "Is Secret" toggle.
- **"Save & Redeploy" Button**:
  - For non-local environments (Production/Staging): Saves variables, pushes to cluster, and restarts all deployment pods with one click.
  - For local environment: Saves to `.env` and notifies user (or triggers local restart if running).
- **Vault Status Header**:
  - Displays `🛡️ OpenBao Vault` (with link to OpenBao Web UI) or `⚙️ Kubernetes Secret`.
- **Sync Actions**:
  - "Push to Cluster" (`dotenv:push`) and "Pull to Local" (`dotenv:pull`) with clear tooltips.

---

### Component 2: Desktop Dotenv Controller (`desktop`)

#### [MODIFY] [`desktop/app/Http/Controllers/ProjectDotenvController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/ProjectDotenvController.php)
- Add **`update(Request $request, Project $project): JsonResponse`**:
  - Receives `environment`, `variables` (key-value array), and `redeploy` (boolean).
  - Merges updated variables into `.env.{environment}` on disk.
  - Runs `dotenv:push {environment}`.
  - If `redeploy=true` and targeting non-local (or local with active cluster), executes `kubectl rollout restart deployment -l 'larakube-preview!=true,larakube.dev/role!=share' -n <namespace>`.
  - Returns updated drift status and redeployment confirmation.

#### [MODIFY] [`desktop/routes/web.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/routes/web.php)
- Register `POST /projects/{project}/dotenv/update` route pointing to `ProjectDotenvController@update`.

---

### Component 3: Universal Naming Convention (`app-secrets` / `app-config`) (`cli`)

#### [MODIFY] [`cli/app/Data/ConfigData.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Data/ConfigData.php)
- Add canonical helper methods:
  ```php
  public function getSecretName(): string
  {
      return 'app-secrets';
  }

  public function getConfigMapName(): string
  {
      return 'app-config';
  }

  public function getLegacySecretName(): string
  {
      return 'laravel-secrets';
  }

  public function getLegacyConfigMapName(): string
  {
      return 'laravel-config';
  }
  ```

#### [MODIFY] [`cli/app/Traits/ReadsEnvSources.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/ReadsEnvSources.php) & [`cli/app/Commands/DotenvCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/DotenvCommand.php)
- Update cluster secret reading to check `app-secrets` first; if not found, fall back to `laravel-secrets`.
- Update configmap reading to check `app-config` first; if not found, fall back to `laravel-config`.

#### [MODIFY] [`cli/resources/views/k8s/`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/resources/views/k8s/)
- Update workload templates (e.g. `base/deployment.blade.php`, `server/deployment.blade.php`, `overlays/local/deployment-patch.blade.php`, etc.) to use `app-secrets` and `app-config` as canonical names.
- Update `dotenv:push` to write to `app-secrets` (or OpenBao ESO sync destination).

---

### Component 4: SPA Environment Variable Parity (`cli`)

#### [MODIFY] [`cli/app/Enums/AppFramework.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Enums/AppFramework.php)
- Replace rigid `requiresEnvFile(): bool` with framework-aware logic:
  - `supportsEnvFile(): bool` -> returns `true` for all frameworks (including Astro, Vite, Next.js).
  - `mustHaveEnvFile(): bool` -> returns `true` for backend servers (Laravel, Django, etc.), `false` for static sites.
  - If a static site has an `.env` file or backend dependencies (PocketBase, APIs), `UpCommand` and `DotenvCommand` treat it with full parity.

#### [MODIFY] [`cli/app/Commands/UpCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/UpCommand.php)
- If `.env` or `.env.example` exists, always process and sync it, regardless of whether the framework is Astro or Laravel.
- If `.env` is absent on a static site without a backend, proceed gracefully without error.

---

## Verification Plan

### Automated Tests
1. **Desktop Tests**:
   - Write tests for `ProjectDotenvControllerTest.php` covering `status`, `push`, `pull`, and `update` (with auto-rollout).
   - Verify TypeScript and formatting: `npm run types` (`tsc --noEmit`) and `vp check`.
2. **CLI Pest Test Suite**:
   - Write tests for `app-secrets` / `laravel-secrets` fallback resolution.
   - Run full Pest suite:
     ```bash
     cd cli && ./vendor/bin/pest
     ```
   - Run formatters and static analysis:
     ```bash
     cd cli && composer format && composer analyse
     ```

### Manual Verification
1. Open LaraKube Desktop:
   - Navigate to a project show page: verify the terminal UI card is removed.
   - Switch between "Local" and "Production" environments.
   - Inspect the new inline **Environment Variables & Secrets** table:
     - Toggle "Reveal Values" to verify plaintext peek.
     - Add a new variable and edit an existing variable.
     - Click "Save & Redeploy" in Production: verify that `.env.production` is updated, secrets are pushed, and `rollout restart` triggers.
   - Verify OpenBao status pill and vault UI link when OpenBao is active.
2. Verify an Astro + PocketBase project:
   - Confirm `PUBLIC_POCKETBASE_URL` is parsed, displayed, and synced correctly.
