# Twenty CRM Full Resilience & Background Worker Plan

## Executive Summary
Twenty CRM's built-in **Health Dashboard** (`Settings → Health`) monitors five critical subsystem layers:
1. **Database** (PostgreSQL) — Operational 🟢
2. **Redis** (Key-value store & BullMQ queue backend) — Operational 🟢
3. **Worker** (Background Queue & Sync Daemon) — Currently **Outage** 🔴
4. **Connected Accounts** (Mail/Calendar syncing state) — Operational 🟢
5. **App** (Web/API Server) — Operational 🟢

The **Worker: Outage** status occurs because Twenty CRM splits its execution architecture into two distinct workloads sharing the same environment and container image (`twentycrm/twenty`):
1. **Web Server (`crm-twenty`)**: Serves HTTP API requests, GraphQL, and the UI.
2. **Worker Service (`crm-twenty-worker`)**: Runs background queues via `yarn worker:prod` for email synchronization (IMAP/SMTP), calendar syncing (CalDAV), workflow automation execution, and scheduled cron triggers.

---

## Architectural Objectives

1. **Deploy Dedicated Twenty CRM Worker (`crm-twenty-worker`)**:
   - Add a worker `Deployment` resource in `cli/resources/views/k8s/crm/shared.blade.php`.
   - Configure container command to `["yarn", "worker:prod"]`.
   - Share all environment variables (`PG_DATABASE_URL`, `REDIS_URL`, `APP_SECRET`, `ENCRYPTION_KEY`, `SERVER_URL`, OIDC keys) with the primary web deployment.
   - Resource limits: `requests: 256Mi / 50m`, `limits: 1536Mi / 500m` with `NODE_OPTIONS="--max-old-space-size=1024"`.

2. **Full Teardown Alignment**:
   - Update `CrmRemoveCommand.php` to include `deployment/crm-twenty-worker` (or instance-suffixed `deployment/crm-twenty-worker-{$instance}`) in teardown calls.

3. **Multi-Instance Support**:
   - Ensure the worker deployment names cleanly scale across multi-instance targets (e.g. `crm-twenty-worker-luchtech-dev`).

---

## Implementation Steps

### Step 1: Update Kubernetes View Manifest
Modify `cli/resources/views/k8s/crm/shared.blade.php` to append the Worker deployment manifest:

```yaml
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $workerDeploymentName ?? ($deploymentName ? $deploymentName.'-worker' : 'crm-twenty-worker') }}
  namespace: larakube-shared
  labels:
    app: {{ $workerDeploymentName ?? ($deploymentName ? $deploymentName.'-worker' : 'crm-twenty-worker') }}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $workerDeploymentName ?? ($deploymentName ? $deploymentName.'-worker' : 'crm-twenty-worker') }}
  template:
    metadata:
      labels:
        app: {{ $workerDeploymentName ?? ($deploymentName ? $deploymentName.'-worker' : 'crm-twenty-worker') }}
    spec:
      containers:
        - name: twenty-worker
          image: twentycrm/twenty:v2.24.1
          command: ["yarn", "worker:prod"]
          env:
            - name: PORT
              value: "3000"
            - name: NODE_OPTIONS
              value: "--max-old-space-size=1024"
            # (Same shared env keys: DB_PASSWORD, SERVER_URL, PG_DATABASE_URL, REDIS_URL, APP_SECRET, ENCRYPTION_KEY, SSO_OIDC_*)
```

### Step 2: Update `CrmRemoveCommand.php` Teardown List
Update `cli/app/Commands/Crm/CrmRemoveCommand.php`:

```php
$workerName = ($instance === null || $instance === '' || $instance === 'main') 
    ? 'crm-twenty-worker' 
    : "crm-twenty-worker-{$instance}";

$resources = "deployment/{$deploymentName} deployment/{$workerName} service/{$serviceName} ingress/{$serviceName} "
    ."secret/{$secretName} secret/{$oidcSecretName}";
```

### Step 3: Verification Strategy
1. Run `./php vendor/bin/pest tests/Feature/CrmInitCommandTest.php` to verify manifest rendering and command test assertions.
2. Deploy live via `./build && larakube crm:init local`.
3. Check pod status via `kubectl get pods -n larakube-shared` (verify `crm-twenty-worker-*` is `1/1 Running`).
4. Inspect Twenty CRM Health Settings UI: verify **Worker** status transitions to **Operational** 🟢.
