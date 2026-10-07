# Architectural Plan: Plex Commons Scaling & DevBox Project Parity

## 1. Problem Statement & Motivation
1. **Plex Commons Connection & Resource Bottlenecks**:
   - `PlexResourcesCommand` (`cli/app/Commands/Plex/PlexResourcesCommand.php`) only configures memory and storage, and offers an interactive-only PgBouncer toggle for Postgres.
   - Postgres defaults to `max_connections = 100`. Multiple tenant applications running Octane workers and queue workers quickly exhaust connection slots.
   - Redis defaults to `timeout 0` (no idle connection reaping) and lacks explicit `maxmemory` / eviction policies (`allkeys-lru`), leading to connection leaks and container OOMKills.
   - SeaweedFS has hardcoded CPU (`500m`) and low default memory limits that throttle S3 throughput and cause OOMKills during large multipart uploads.
   - `plex:resources` lacks non-interactive flags (`--service`, `--memory`, `--cpu`, `--storage`, `--max-connections`, `--maxclients`, `--json`), preventing script automation and Desktop UI integration.

2. **DevBox Project UX & Lifecycle Disparity**:
   - In Desktop, the DevBox project view (`devboxes/project.tsx`) is a stripped-down screen lacking:
     - Multi-environment tabs (`DevBox Local`, `Production`, `Staging`, etc.).
     - Workload Scaling Card (web/worker replicas, CPU/memory, autoscale HPA).
     - Secrets Drift & Dotenv Sync Card (`dotenv:status`, `dotenv:push`, `dotenv:pull`).
     - CI/CD Deployment pipeline controls.
     - Remote-SSH IDE launch integration (VS Code Remote SSH / JetBrains Gateway).
   - This creates cognitive dissonance for developers who expect standard project capabilities whether developing locally on their laptop or remotely on a cloud DevBox.

---

## 2. Technical Architecture & Design

### Phase A: Plex Commons Scaling (`cli/`)
1. **Commons Manifests (`commons.blade.php`)**:
   - **PostgreSQL**:
     - Template `args` for `postgres`:
       - `-c max_connections={{ $spec['services']['postgres']['max_connections'] ?? 200 }}`
       - `-c shared_buffers={{ $spec['services']['postgres']['shared_buffers'] ?? '128MB' }}`
     - Template configurable CPU requests and limits:
       - `limits.cpu: {{ $spec['services']['postgres']['cpu'] ?? '1000m' }}`
       - `requests.cpu: {{ $spec['services']['postgres']['cpu_request'] ?? '100m' }}`
   - **Redis**:
     - Add command arguments or startup flags to Redis container:
       - `command: ["redis-server", "--maxclients", "{{ $spec['services']['redis']['maxclients'] ?? 10000 }}", "--maxmemory-policy", "{{ $spec['services']['redis']['maxmemory_policy'] ?? 'allkeys-lru' }}", "--timeout", "{{ $spec['services']['redis']['timeout'] ?? 300 }}"]`
     - Template configurable CPU requests and limits:
       - `limits.cpu: {{ $spec['services']['redis']['cpu'] ?? '500m' }}`
   - **SeaweedFS**:
     - Template configurable CPU and Memory limits:
       - `limits.cpu: {{ $spec['services']['seaweedfs']['cpu'] ?? '1000m' }}`
       - `limits.memory: {{ $spec['services']['seaweedfs']['memory'] ?? '1Gi' }}`
     - Allow PVC storage expansion via `storage`.
   - **Meilisearch & MySQL/MariaDB**:
     - Apply consistent CPU limits and requests templating.

2. **Plex Resources CLI Command (`PlexResourcesCommand.php`)**:
   - Update signature with non-interactive flags:
     - `--service=` (e.g. `postgres`, `redis`, `seaweedfs`, `meilisearch`)
     - `--memory=` (Kubernetes quantity, e.g. `1Gi`)
     - `--cpu=` (Kubernetes quantity, e.g. `1000m`)
     - `--storage=` (PVC size, e.g. `20Gi`)
     - `--max-connections=` (for Postgres)
     - `--maxclients=` (for Redis)
     - `--pooler=` (enable/disable PgBouncer)
     - `--pool-mode=` (`transaction` or `session`)
     - `--pool-size=` (int)
     - `--json` (machine-readable output)
   - Support both interactive Laravel Prompts and headless flag execution.
   - Maintain strict idempotency and validation.

3. **Plex Spec Normalization (`PlexService.php`)**:
   - Update `normalizeCommonsSpec()` to recognize `cpu`, `max_connections`, `shared_buffers`, `maxclients`, `maxmemory_policy`, and `timeout`.
   - Provide sensible defaults:
     - Postgres: `max_connections = 200`, `cpu = '1000m'`, `memory = '512Mi'`
     - Redis: `maxclients = 10000`, `maxmemory_policy = 'allkeys-lru'`, `timeout = 300`, `cpu = '500m'`, `memory = '256Mi'`
     - SeaweedFS: `cpu = '1000m'`, `memory = '1Gi'`, `storage = '10Gi'`

---

### Phase B: Mail SSO Integration (`desktop/`)
1. **Controller & Routes (`MailController.php`, `web.php`)**:
   - Add `syncSso()` action: `POST /servers/{server}/mail/sync-sso` -> executes `mail:sync-sso production --context={context}`.
   - Update `createAccount()` to accept `sso` boolean (passing `--sso` or `--no-sso`).
   - Update `resetPassword()` to accept `sso` boolean (passing `--sso` or `--no-sso`).
   - Inject `hasSso` into `index()` so the frontend knows if Zitadel is present on the server.
2. **UI Enhancements (`mailboxes-tab.tsx`)**:
   - Add "Sync to SSO" button with icon (`RotateCw` / `ShieldCheck`) in the mailboxes header (active when `hasSso` is true).
   - Add checkbox in Create Mailbox modal: `[x] Create matching SSO identity in Zitadel`.
   - Add checkbox in Reset Password modal: `[x] Also update matching SSO identity in Zitadel`.
   - Add visual SSO status indicator or badge per mailbox row.

---

### Phase C: DevBox Project Parity (`desktop/`)
1. **Backend Integration (`DevBoxController.php`)**:
   - Add DevBox scaling endpoints forwarding to `CliRunner` over SSH:
     - `POST /dev-boxes/{box}/projects/{project}/scaling/replicas` -> `scale:replicas`
     - `POST /dev-boxes/{box}/projects/{project}/scaling/resources` -> `scale:resources`
     - `POST /dev-boxes/{box}/projects/{project}/scaling/autoscale` -> `scale:autoscale`
   - Add DevBox dotenv drift endpoints:
     - `GET /dev-boxes/{box}/projects/{project}/dotenv/status` -> `dotenv:status --json`
     - `POST /dev-boxes/{box}/projects/{project}/dotenv/push` -> `dotenv:push`
     - `POST /dev-boxes/{box}/projects/{project}/dotenv/pull` -> `dotenv:pull`
   - Enhance `showProject()` payload:
     - Read `.larakube.json` environments from the DevBox so cloud environments (`production`, etc.) can be displayed in tabs alongside `DevBox Local`.
     - Pass scaling specs, dotenv drift status, and remote IDE connection URLs.

2. **Frontend UI Upgrade (`devboxes/project.tsx` & Shared Components)**:
   - Introduce Environment tabs: `[DevBox Local]` + `[Production]` + `[Staging]`.
   - Embed `WorkloadScalingCard` in the DevBox project view, bound to DevBox SSH actions.
   - Embed `EnvironmentSecretsCard` in the DevBox project view, allowing 1-click Push/Pull of `.env` drift on the box.
   - Retain DevBox-specific `SharingCard` (`share:domain` Cloudflare tunnel).
   - Add "Open in Editor" button supporting VS Code Remote-SSH:
     - Link format: `vscode://vscode-remote/ssh-remote+<user>@<ip>/home/<user>/projects/<project>`
     - JetBrains Gateway link or SSH instructions.

---

### Phase D: Future Dedicated Identity & SSO Section (Milestone 2)
- Dedicated `/servers/{server}/sso` route in Desktop navigation alongside Mail and Tools.
- Identity Dashboard:
  - Users tab: List Zitadel users, create (`sso:create`), remove (`sso:remove`), grant roles.
  - Connected Apps tab: View tools wired to SSO, wire/unwire (`sso:wire`, `sso:unwire`).
  - Organizations tab: View and manage tenant orgs (`sso:org`).

---

## 3. Implementation Steps & TDD Verification

1. **Step 1: CLI Plex Commons Manifests & Normalization**:
   - Update `cli/app/Services/PlexService.php` with default specs for CPU and connection parameters.
   - Update `cli/resources/views/k8s/plex/commons.blade.php` to render custom args for Postgres, Redis, SeaweedFS, and Meilisearch.
   - Write/update tests in `cli/tests/Feature/Plex/` verifying manifest generation with custom connection & CPU limits.

2. **Step 2: CLI `plex:resources` Enhancements**:
   - Add non-interactive flags (`--service`, `--memory`, `--cpu`, `--storage`, `--max-connections`, `--maxclients`, `--json`) and interactive prompts in `PlexResourcesCommand.php`.
   - Write tests verifying both interactive prompt flows and headless flag execution.
   - Run `composer format`, `composer analyse`, `composer test`.

3. **Step 3: Desktop Mail SSO Integration**:
   - Update `MailController.php` with `syncSso()` and `--sso` / `--no-sso` flags.
   - Update `mailboxes-tab.tsx` with "Sync to SSO" button and modal checkboxes.
   - Write tests in `desktop/tests/Feature/MailTest.php`.

4. **Step 4: Desktop Backend DevBox Scaling & Dotenv Endpoints**:
   - Add routes and controller methods for DevBox scaling and dotenv actions in `desktop/routes/web.php` and `desktop/app/Http/Controllers/DevBoxController.php`.
   - Update `showProject()` to resolve environments and configuration from the DevBox.
   - Write Desktop feature tests in `desktop/tests/Feature/DevBoxProjectTest.php`.

5. **Step 5: Desktop UI DevBox Parity & Verification**:
   - Update `desktop/resources/js/pages/devboxes/project.tsx` with Environment tabs, `WorkloadScalingCard`, `EnvironmentSecretsCard`, and Remote-SSH IDE actions.
   - Run `composer ci:check` (Biome, TypeScript, Pest).

---

## 4. Key Limitations & Developer Guidelines to Document
- **Local Browser Access**: DevBox apps use `*.test` internally; external access requires Cloudflare tunnel (`share:domain`), SSH port-forwarding, or NetBird VPN.
- **Compute Ceiling**: All apps on a DevBox share that single VM's hardware capacity.
- **SSH Round-Trip**: Commands incur ~200-500ms SSH latency compared to local machine execution.
- **Remote Filesystem**: Project files reside in `~/projects/<name>` on the box; editing requires Remote SSH or Git sync.

---

## 5. Execution Summary & Verification

### Status: Complete ✅
- **CLI (`c2d0f00b`)**: `feat(cli): add plex commons connection and cpu scaling controls with sso status in mail`
  - Added CPU, `max_connections`, `shared_buffers`, `maxclients`, `maxmemory_policy`, `timeout` parameters to `PlexService` and `commons.blade.php`.
  - Added non-interactive headless flags to `PlexResourcesCommand` (`--service`, `--memory`, `--cpu`, `--storage`, `--max-connections`, `--maxclients`, `--pooler`, `--json`).
  - Added SSO status detection into `mail:show --json` and `mail:accounts --json`.
  - 3,125 tests passed in Pest, 0 PHPStan errors.
- **Desktop Mail SSO (`7adeeaf`)**: `feat(desktop): add zitadel sso sync and account provisioning in mail`
  - Integrated `mail:sync-sso` endpoint, `--sso`/`--no-sso` flags on `mail:create` and `mail:password`.
  - Added "Sync to SSO" action button in `mailboxes-tab.tsx`, SSO creation/password update checkboxes in modals, and SSO indicators on mailbox rows.
  - 14 tests in `MailTest.php` passing, Biome & types verified.
- **Desktop DevBox Parity (`52de358`)**: `feat(desktop): add multi-environment tabs, scaling, dotenv sync, and remote ide to devbox projects`
  - Added DevBox scaling endpoints (`scaleReplicas`, `scaleAutoscale`, `scaleResources`) and dotenv drift sync (`dotenvStatus`, `dotenvPush`, `dotenvPull`) executed over SSH in the remote project directory.
  - Added multi-environment tabs (`DevBox Local`, `Production`, `Staging`, etc.) in `devboxes/project.tsx`.
  - Embedded `WorkloadScalingCard` and `EnvironmentSecretsCard` with dynamic `customEndpoints`.
  - Added "Open in Editor" integration supporting VS Code Remote-SSH, Cursor Remote-SSH, and direct SSH terminal commands.
  - 385 tests passed in Pest, Pint passed, PHPStan passed, Biome passed.
