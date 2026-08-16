# Plan: Twenty CRM Container Resilience & Environment Tuning

## Goal Description
Resolve Twenty CRM container startup delay and log warnings (`relation "core.keyValuePair" does not exist`) by tuning Twenty CRM's required environment variables, optimizing container memory/CPU limits, and configuring explicit auto-migration flags.

---

## User Review Required

> [!NOTE]
> **Non-Fatal Log Warning Explained**: The log line `ERROR [DatabaseConfigDriver] [INIT] Failed to load config variables from database, falling back to environment variables (relation "core.keyValuePair" does not exist)` is Twenty CRM's built-in fallback behavior when connecting to a brand-new database for the first time. On initial boot, NestJS attempts to load dynamic DB settings; when it finds an unmigrated database, it logs this fallback message, proceeds to run TypeORM migrations, creates `core.keyValuePair`, and completes startup.

> [!IMPORTANT]
> **Resource Allocation & Boot Time**: Twenty CRM is an enterprise NestJS CRM that executes 200+ database migrations, schema initializations, workspace upgrades, and 26 cron registrations on initial boot. With 2Gi memory limit and 1000m CPU limit, initial cold boot takes ~2 to 3 minutes before the HTTP health check turns `1/1 Ready`.

---

## Proposed Changes

### 1. Update Twenty CRM Manifest Template (`cli/resources/views/k8s/crm/shared.blade.php`)

Add complete explicit environment variables required by Twenty CRM v2:
- `SERVER_URL`: `"https://{{ $host }}"` (in addition to `FRONT_BASE_URL`)
- `PORT`: `"3000"`
- `IS_AUTO_MIGRATION_ENABLED`: `"true"`
- `STORAGE_TYPE`: `"local"`
- `DISABLE_TELEMETRY`: `"true"`
- `NODE_OPTIONS`: `"--max-old-space-size=1536"`
- `ENCRYPTION_KEY` & `APP_SECRET` secret bindings
- Memory Request: `512Mi`, Limit: `2Gi`
- CPU Request: `100m`, Limit: `1000m`

### 2. Update `CrmInitCommand.php`

Ensure `CrmInitCommand.php` generates and syncs `encryption-key` secret key and passes all required parameters to the manifest view.

---

## Diffs & Implementation Details

```diff
--- a/cli/resources/views/k8s/crm/shared.blade.php
+++ b/cli/resources/views/k8s/crm/shared.blade.php
@@ -26,8 +26,16 @@ spec:
           env:
+            - name: PORT
+              value: "3000"
             - name: NODE_OPTIONS
               value: "--max-old-space-size=1536"
+            - name: IS_AUTO_MIGRATION_ENABLED
+              value: "true"
+            - name: STORAGE_TYPE
+              value: "local"
+            - name: DISABLE_TELEMETRY
+              value: "true"
             - name: FRONT_BASE_URL
               value: "https://{{ $host }}"
+            - name: SERVER_URL
+              value: "https://{{ $host }}"
```

---

## Verification Plan

### Automated Tests
Run Pest test suite to ensure manifest rendering and command execution tests pass:
```bash
php vendor/bin/pest tests/Feature/CrmInitCommandTest.php
php vendor/bin/pint
php vendor/bin/phpstan analyse --memory-limit=1G
```

### Manual Verification
1. Re-build LaraKube CLI: `./build`
2. Run `larakube crm:init production` (or target cluster context).
3. Monitor container startup via `kubectl get pods -n larakube-shared --watch` until `1/1 Running`.
