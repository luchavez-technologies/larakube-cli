# Plan: OpenBao Database Secrets Engine — Static Role Rotation & Dynamic Credentials

> **🛑 LIVE CHANGES — Read before editing to avoid collisions**
> Committed by Mechanic on 2026-07-30. The following files were modified/created and may overlap with what the Architect (Claude) is editing:
> - `cli/app/Traits/SyncsClusterSecrets.php` — added `registerStaticRole()`, `databaseEngineMounted()`, `mountDatabaseEngine()`, `writeDatabaseEngineConfig()`, `readDatabaseRootPassword()`, `wireDatabaseEngineToOpenBao()`. **NOTE:** Moved `wireDatabaseEngineToOpenBao()` out of `InteractsWithPlex.php` (plan said that, but it's in `SyncsClusterSecrets` instead — uses `InteractsWithSecrets::openBaoApi()`)
> - `cli/resources/views/k8s/secrets/eso-db-static.blade.php` — created (ESO ExternalSecret template for static-creds)
> - `cli/app/Commands/Secrets/SecretsWireCommand.php` — created (scoped down: no `--tool`/`--tenant` filter, no ESO ExternalSecret migration, no DB engine wiring — registers roles only)
> - `cli/app/Commands/Plex/PlexInitCommand.php` — added `wireDatabaseEngineToOpenBao(enabledServices)` call after Commons provisioning
> - `cli/app/Commands/Plex/PlexJoinCommand.php` — replaced `pushClusterSecret('DB_PASSWORD')` with `registerStaticRole()` when engine mounted
> - `cli/app/Commands/Sign/SignInitCommand.php` — DB password → registerStaticRole/KV fallback
> - `cli/app/Commands/Record/RecordInitCommand.php` — DB password → registerStaticRole/KV fallback
> - `cli/app/Commands/Sso/SsoInitCommand.php` — DB password → registerStaticRole/KV fallback
> - `cli/app/Commands/Password/PasswordsInitCommand.php` — DB password → registerStaticRole/KV fallback
> - `cli/app/Commands/Mail/MailInitCommand.php` — DB password → registerStaticRole/KV fallback
> - `cli/app/Commands/Git/GitInitCommand.php` — DB password → registerStaticRole/KV fallback
> - `cli/app/Enums/ClusterTool.php` — fixed `commonsDatabaseList()`: SIGN→`sign_documenso`, LINK→`link_kutt`, SUPPORT→`support_chatwoot`, TASKS→`tasks_planka`
> - `cli/tests/Unit/SyncsClusterSecretsTest.php` — 14 new tests for all new trait methods
> - `cli/tests/Feature/SsoInitCommandTest.php` — 2 new tests (registerStaticRole path, KV fallback path)
>
> **Important divergence from plan:**
> - `wireDatabaseEngineToOpenBao()` lives in `SyncsClusterSecrets.php` (NOT `InteractsWithPlex.php`) so any command with the trait can call it
> - `allowed_roles` is `'*'` not `'tenant-*'` — discovered via live test that tool roles (`forgejo`, `zitadel`, etc.) don't match `tenant-*` glob
> - `secrets:wire` does NOT mount the DB engine or wire engine config — that's still `plex:init`'s job. `secrets:wire` only registers static roles.
>
> **Tests:** 1090 pass (was 1074 before). PHPStan clean.

## 🎯 Objective

Evolve **every LaraKube-managed database credential** from static KV secrets (never rotated) to **OpenBao-managed static roles** with automatic rotation. This covers two categories:

| Category | Examples | Created by |
|----------|----------|------------|
| **Cluster tools** | Stalwart, Forgejo, Zitadel, Vaultwarden, Teable, Outline, n8n, Planka, FreeScout, Documenso, Sendrec, Twenty, Kutt, Chatwoot, Umami, GlitchTip, Metabase, Matrix/Synapse, oCIS/Nextcloud | `{tool}:init` |
| **App tenants** | User project databases | `plex:join` |

All go through the Plex Commons. Three Plex-ready database engines are supported. Each gets its own connection config in OpenBao's DB secrets engine:

| Engine | Root user | OpenBao plugin | `database/config/` name | Tools using it |
|--------|-----------|----------------|------------------------|----------------|
| PostgreSQL | `postgres` | `postgresql-database-plugin` | `plex-postgres` | Stalwart, Forgejo, Zitadel, Vaultwarden, Teable, Synapse, Twenty, Sendrec, Kutt, Chatwoot, Umami, Planka oCIS, Notes |
| MySQL | `root` | `mysql-database-plugin` | `plex-mysql` | (selected by operator) |
| MariaDB | `root` | `mysql-database-plugin` | `plex-mariadb` | (selected by operator) |

MongoDB and SQLite are **not** Plex-ready (`isPlexReady() === false`) — they have no Commons tenant model and are out of scope.

Two-tier approach:

| Tier | What | When |
|------|------|------|
| **Phase 1 — Static Role Rotation** | OpenBao rotates each tool/tenant's password on a schedule; ESO syncs the new password to the workload namespace | Immediate win, no sidecar needed |
| **Phase 2 — Dynamic Credentials** | Each pod replica gets a unique, short-lived DB user; sidecar injects creds at runtime | Requires Vault Agent sidecar, app-level reconnection handling |

---

## 🔒 Threat Model

### Current (static KV)
- `plex:join` generates `$password = bin2hex(random_bytes(16))`, creates ROLE + DATABASE via `kubectl exec`, pushes to OpenBao KV as `secret/data/production/DB_PASSWORD`.
- Password lives **forever** — same user, same password until `plex:join --rotate`.
- Compromised pod == permanent DB access for the attacker.

### Phase 1 — Static Rotation
- OpenBao holds the root Postgres/MySQL connection. Tenant password rotates every N hours.
- Compromised pod exposes a credential valid for at most N hours.
- No app code changes — `DB_USERNAME` stays the same, only `DB_PASSWORD` changes. ESO syncs the new password to `laravel-secrets` within seconds of rotation; `restartSecretConsumers()` triggers a rollout.

### Phase 2 — Dynamic
- Each pod replica gets `v-<app>-<random>-<timestamp>`, TTL 1–12h.
- Compromised pod = one user, one lease. Revoke the lease = `DROP USER` cluster-wide.
- Requires the app to re-authenticate on connection drops.

---

## 🧱 Phase 1: Static Role Rotation (Immediate)

### 1. `plex:init` — Register root connection with OpenBao DB Engine

After deploying each Plex-ready Commons database pod (PostgreSQL, MySQL, MariaDB), if OpenBao is present, configure the **database secrets engine**:

```
openbao mount database
```

Then for each database service in the Commons, `wireDatabaseEngineToOpenBao()` detects the driver and writes the correct config:

**PostgreSQL:**
```bash
openbao write database/config/plex-postgres \
    plugin_name=postgresql-database-plugin \
    allowed_roles="tenant-*" \
    connection_url="postgresql://{{username}}:{{password}}@postgres.larakube-plex.svc:5432/postgres?sslmode=disable" \
    username="postgres" \
    password="$POSTGRES_PASSWORD"
```

**MySQL:**
```bash
openbao write database/config/plex-mysql \
    plugin_name=mysql-database-plugin \
    allowed_roles="tenant-*" \
    connection_url="{{username}}:{{password}}@tcp(mysql.larakube-plex.svc:3306)/" \
    username="root" \
    password="$MYSQL_ROOT_PASSWORD"
```

**MariaDB:** Same plugin as MySQL, uses `database/config/plex-mariadb` and the mariadb pod's hostname + root password.

**Idempotency:** Check if `database/config/plex-<driver>` already exists via `openbao read`. If it does, verify the root password hasn't drifted (re-write if the pod's actual root password differs).

**Where:** In `InteractsWithPlex.php` — a new `wireDatabaseEngineToOpenBao()` method that discovers which Plex-ready engines are deployed (via `$spec['services']`) and writes config for each. Called at the end of `plex:init`'s handle flow. Uses `$this->openBaoApi()` (already available via `InteractsWithSecrets`).

**Root password source:** Read from the database pod's own environment (`kubectl exec deploy/postgres -- env` for `POSTGRES_PASSWORD`, etc.) rather than from a hard-coded variable, so it works regardless of which engine the operator chose.

### 2. `plex:join` — Register tenant as a static role

Currently `plex:join`:
1. Calls `allocateDatabase()` → generates SQL, pipes via `kubectl exec`, creates ROLE + DB
2. Calls `pushClusterSecret('DB_PASSWORD', $password)` → OpenBao KV

New flow:
1. `allocateDatabase()` unchanged — still creates the ROLE + DATABASE via SQL
2. Instead of `pushClusterSecret()`, register a **static role** in the DB engine:

```bash
openbao write database/static-roles/tenant-<app>-<env> \
    db_name=plex-postgres \
    username="<tenant-role-name>" \
    rotation_period="24h"
```

OpenBao then:
- Reads the current password from the database for that user
- Stores an encrypted copy in its own backend
- Rotates it every `rotation_period` by generating a new password and `ALTER ROLE ... PASSWORD`-ing it

**Where:** In `PlexJoinCommand::handle()`, lines 251–261. Replace the `pushClusterSecret`/`syncClusterSecretToNamespace` block with a conditional: if OpenBao DB engine is configured, create a static role; otherwise fall back to KV push.

**Existing tenant migration (`plex:join --rotate`):**
- When `--rotate` is passed on an already-joined tenant, convert it from KV to static role:
  1. Read existing password from OpenBao KV
  2. Register static role (OpenBao picks up the current password)
  3. Delete the old KV entry
  4. Switch the ESO `ExternalSecret` to point at `database/static-creds/tenant-<app>-<env>` instead of `secret/data/...`

### 3. `secrets:wire` — Register all DB credentials as static roles

```
larakube secrets:wire
    {--rotation-period=168h : Static role rotation interval (default 7d)}
    {--tool= : Specific cluster tool (omit for all from ClusterTool)}
    {--tenant= : Specific app tenant (omit for all from Plex Registry)}
```

**Scope** — covers EVERY database user managed by LaraKube:

1. **Cluster tools** — discovered from `ClusterTool::cases()` where `commonsDatabases()` is non-empty. For each installed tool (detected via `kubectl get namespace/namespace` or `kubectl get deployment/name`), register its DB user (`forgejo`, `stalwart`, `zitadel`, etc.) as an OpenBao static role.
2. **App tenants** — discovered from the Plex Registry ConfigMap. For each tenant, register its DB user as a static role.
3. **`--tool`** and **`--tenant`** filter to a single entry for targeted operations.

**Actions per entry:**
1. Reads the tool/tenant's current DB user and password from OpenBao KV (or detects it from the DB directly via OpenBao's connection)
2. Registers/updates a static role: `openbao write database/static-roles/<name>`
3. Creates/updates an `ExternalSecret` in the tool/tenant's namespace that fetches `DB_USERNAME` + `DB_PASSWORD` from `database/static-creds/<name>` instead of KV
4. Removes the old KV entry (password is now managed by the DB engine)
5. Restarts the deployment so it picks up the (unchanged) password via ESO

**One-time seed:** On first-ever run, `secrets:wire` also mounts the `database` engine and writes `database/config/plex-postgres` (the Postgres root connection) — same as what `plex:init` does in step 1. This makes `secrets:wire` the single entry point: run it after installing OpenBao, and all existing credentials get rotation.

### 4. ESO Integration — Static Role Secret Template

New view template: `resources/views/k8s/secrets/eso-db-static.blade.php`

```yaml
apiVersion: external-secrets.io/v1beta1
kind: ExternalSecret
metadata:
  name: {{ $secretName }}-db
  namespace: {{ $namespace }}
spec:
  refreshInterval: 5m
  secretStoreRef:
    kind: ClusterSecretStore
    name: openbao
  target:
    name: {{ $secretName }}
    creationPolicy: Merge
  data:
  - secretKey: DB_USERNAME
    remoteRef:
      key: /database/static-creds/tenant-{{ $app }}-{{ $environment }}
      property: username
  - secretKey: DB_PASSWORD
    remoteRef:
      key: /database/static-creds/tenant-{{ $app }}-{{ $environment }}
      property: password
```

This merges into the existing `laravel-secrets` — ESO adds/updates `DB_USERNAME` and `DB_PASSWORD` while leaving other keys untouched.

### 5. Rotation Trigger

OpenBao's static roles rotate automatically based on `rotation_period`. ESO's `refreshInterval: 5m` picks up the new password within 5 minutes of a rotation.

For zero-downtime password rotation in Laravel:
- Laravel's DB connection pool reconnects automatically on the next request after a failed query
- The old password remains valid for a **grace period** (`rotation_window` in OpenBao static roles) so in-flight connections don't break
- After rotation, run `restartSecretConsumers()` to trigger a gradual rollout

---

## 🧱 Phase 2: Dynamic Credentials (Future)

### Vault Agent Sidecar Injection

Requires the `vault.hashicorp.com/agent-inject` annotations in each workload pod template. LaraKube would:

1. Create a Kubernetes auth role in OpenBao bound to the app's ServiceAccount
2. Annotate the deployment template:
   ```yaml
   metadata:
     annotations:
       vault.hashicorp.com/agent-inject: "true"
       vault.hashicorp.com/role: "app-<name>"
       vault.hashicorp.com/agent-inject-secret-db: "database/creds/tenant-<app>-<env>"
   ```
3. Inject a `DATABASE_URL` env var that reads the sidecar file:
   ```
   DB_CONNECTION: pgsql
   DB_HOST: postgres.larakube-plex.svc
   DB_PORT: "5432"
   DB_DATABASE: <tenant-db>
   DB_USERNAME: file:///vault/secrets/db (username)
   DB_PASSWORD: file:///vault/secrets/db (password)
   ```

**Not implemented yet.** Blocked on:
- OpenBao Agent (fork of Vault Agent) maturity for sidecar injection
- App-level reconnection handling (Laravel's `pg_connect` doesn't re-read files on reconnect)
- A decision on per-pod vs. per-deployment credential model

---

## 📋 Implementation Checklist

### Phase 1 — Static Role Rotation

| # | Task | File(s) | Priority |
|---|------|---------|----------|
| P1.1 | Add `wireDatabaseEngineToOpenBao()` to `InteractsWithPlex.php` — mounts `database` engine, writes `database/config/plex-{postgres,mysql}` using the root password from the Plex K8s Secret | `cli/app/Traits/InteractsWithPlex.php` | High |
| P1.2 | Call it from `PlexInitCommand::handle()` after Commons DB is ready (also makes `secrets:wire db` callable standalone to seed the engine) | `cli/app/Commands/Plex/PlexInitCommand.php` | High |
| P1.3 | Add `registerStaticRole()` method — wraps `POST /v1/database/static-roles/<name>` with the tool/tenant's existing DB username | `cli/app/Traits/SyncsClusterSecrets.php` | High |
| P1.4 | Create `SecretsWireCommand` — `larakube secrets:wire` that: (a) seeds DB engine if needed, (b) discovers installed tools from `ClusterTool::commonsDatabaseList()`, (c) discovers app tenants from Plex Registry, (d) registers each as a static role, (e) creates/updates ESO ExternalSecrets, (f) removes old KV entries | `cli/app/Commands/Secrets/SecretsWireCommand.php` | High |
| P1.5 | Create `eso-db-static.blade.php` view template — ExternalSecret reading `database/static-creds/<name>` with `creationPolicy: Merge` | `cli/resources/views/k8s/secrets/eso-db-static.blade.php` | High |
| P1.6 | Modify all `{tool}:init` commands (Stalwart, Forgejo, Zitadel, Vaultwarden, Teable, Outline, n8n, Planka, FreeScout, Documenso, Sendrec, Twenty, Kutt, Chatwoot, Umami, GlitchTip, Metabase, Matrix, oCIS, etc.) to call `registerStaticRole()` instead of `pushClusterSecret()` for DB passwords, when the DB engine is configured | `cli/app/Commands/*/*InitCommand.php` | High |
| P1.7 | Modify `PlexJoinCommand::handle()` — same: replace `pushClusterSecret('DB_PASSWORD')` with `registerStaticRole()` when DB engine is configured | `cli/app/Commands/Plex/PlexJoinCommand.php` | High |
| P1.8 | Handle `--rotate` in `plex:join` — convert KV-based existing tenant to static role (read old KV → register → delete KV → switch ESO) | `cli/app/Commands/Plex/PlexJoinCommand.php` | Medium |
| P1.9 | Add `restartSecretConsumers()` call after role registration so pods pick up the (unchanged) password via ESO on restart | `SyncsClusterSecrets.php` | Medium |
| P1.10 | Write tests for all new/altered code paths | `cli/tests/` | Required |

### Phase 2 — Dynamic Credentials

| # | Task | Priority |
|---|------|----------|
| P2.1 | Evaluate OpenBao Agent sidecar maturity and compatibility | Low (investigate) |
| P2.2 | Design DynamicRole view templates for injection annotations | Low |
| P2.3 | Add `secrets:wire db --dynamic` to register dynamic roles | Low |
| P2.4 | Modify `cloud:deploy` manifest generation to inject sidecar | Low |

---

## 🔁 Idempotency & Migration

### No-OpenBao Guarantee (⭐ Design Requirement)
Every command that gains OpenBao DB engine support must work **identically** when OpenBao is absent:

| Scenario | `plex:join` behavior | Credential lifecycle |
|----------|----------------------|---------------------|
| **No OpenBao at all** | Current flow: writes to `.env` only | Static, manual rotation via `--rotate` |
| **OpenBao installed, no DB engine** | Falls back to KV push (`pushClusterSecret`) | Static in OpenBao, no auto-rotation |
| **OpenBao + DB engine configured** | `registerStaticRole()` + ESO static-creds | Auto-rotated every 7d by OpenBao |
| **OpenBao + DB engine (Phase 2)** | Dynamic role + sidecar injection | Per-pod short-lived user |

**Gating logic** (pseudocode):
```
if (isOpenBaoBootstrapped($kubectl, $ns)):
    if (database engine mounted && database/config/plex-<driver> exists):
        → registerStaticRole()  // Phase 1
    else:
        → pushClusterSecret()   // KV fallback (current behavior)
else:
    → write .env only           // no OpenBao at all
```

This ensures demos work without OpenBao, and the upgrade path is additive — install OpenBao later, run `secrets:wire db`, existing tenants get rotated credentials.

### New tenants (Phase 1)
- `plex:join` → `allocateDatabase()` (SQL) → `registerStaticRole()` (OpenBao DB engine) → ESO syncs rotated password.
- Never touches KV store for DB credentials.

### Cluster tools (Phase 1)
- After OpenBao + DB engine are available, run `secrets:wire db` once.
- Every installed tool's DB user gets registered as a static role.
- Future `{tool}:init` runs detect the DB engine and go directly to `registerStaticRole()` — no KV for DB passwords.
- **Master user:** OpenBao holds the Postgres root password in `database/config/plex-postgres` (set by the seed step). The root password's canonical source is the K8s Secret in `larakube-plex`. OpenBao uses root to rotate each tool/tenant's individual user — the tool never gets root, only its own rotated credentials.

### Existing tenants (Phase 1)
- `plex:join --rotate` → detect KV-based tenant → convert to static role → delete KV entry → switch ESO to static-creds endpoint.
- `secrets:wire db --tenant=<name>` does the same without requiring `--rotate`.

### Fallback (no OpenBao / no DB engine)
- `plex:join` and every `{tool}:init` falls back to the current KV push flow. No behavior change.
- `secretsBackendAvailable()` check gates the new path.
