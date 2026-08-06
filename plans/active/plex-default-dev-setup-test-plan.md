# QA & Teammate Test Plan: Plex Commons Default Local Dev Setup & Driver Matrix

This document provides a step-by-step test plan for team members and QA engineers to verify the **Plex Commons Default Local Dev Setup** and **Driver Compatibility Matrix** in LaraKube.

---

## 🎯 Background: Why We Built This

### The Problem We Solved:
1. **Local RAM Exhaustion**: Previously, scaffolding a new Laravel, Statamic, or polyglot app created separate, isolated MySQL/Postgres database containers for every project, eating up 500MB–1GB RAM per app.
2. **Artificial SQLite Step**: `larakube new` previously forced `--database=sqlite` during initial `laravel new` execution, then manually overwrote `.env` later, creating unnecessary SQLite residual files.
3. **Plex Commons Disconnect**: If Plex Commons was already running shared Postgres or MySQL in the cluster, `larakube new` didn't automatically pre-provision tenant credentials or detect active shared engines.

### The Solution:
- **Plex Commons as Default**: Local apps now reuse shared PostgreSQL/MySQL and SeaweedFS S3 in `larakube-plex`.
- **Auto-Provisioning**: If Plex Commons is not running when a user selects a database driver, LaraKube automatically boots missing Plex Commons services with an informational spinner.
- **Direct DB Credential Injection**: LaraKube pre-provisions the DB tenant (`{$appName}_local`) and credentials, then forwards `--database=pgsql` (or `mysql`) and `-e DB_HOST=host.docker.internal` into `laravel new` so `.env` and initial migrations are written cleanly on step 1!

---

## 📋 Prerequisites for Testing

1. **Rebuild the Binary**:
   ```bash
   ./build
   ```
   *(Or run commands using the local runner `./php larakube ...`)*

2. **Cluster Health Check**:
   ```bash
   larakube doctor
   ```

---

## 🧪 Test Cases

### Test Case 1: Fresh Application Scaffolding with Active Plex Postgres

**Objective**: Verify that `larakube new` pre-provisions a database tenant in Plex Postgres and forwards `--database=pgsql` to `laravel new`.

#### Test Steps:
1. Ensure Plex Commons is initialized:
   ```bash
   larakube plex:init
   ```
2. Create a new Laravel project:
   ```bash
   larakube new my-shared-app
   ```
3. During the wizard, select **PostgreSQL** as the primary database driver.

#### Expected Results:
- [ ] LaraKube displays: `Pre-provisioning Plex Postgres tenant "my_shared_app_local"...`
- [ ] `laravel new` executes with `--database=pgsql` and `-e DB_HOST=host.docker.internal`.
- [ ] Scaffolding completes with zero SQLite residual files.
- [ ] `.env` in `my-shared-app/` contains:
  ```ini
  DB_CONNECTION=pgsql
  DB_HOST=127.0.0.1
  DB_PORT=5432
  DB_DATABASE=my_shared_app_local
  ```

---

### Test Case 2: Auto-Provisioning Missing Plex Commons

**Objective**: Verify that selecting PostgreSQL on a cluster WITHOUT Plex Commons automatically provisions Plex Commons first.

#### Test Steps:
1. Tear down existing Plex Commons (if present):
   ```bash
   larakube plex:remove --force
   ```
2. Run `larakube new`:
   ```bash
   larakube new auto-plex-app
   ```
3. Select **PostgreSQL** as the database engine.

#### Expected Results:
- [ ] LaraKube detects missing Plex Postgres and displays an informational spinner: `Auto-provisioning Plex Commons Postgres...`
- [ ] Plex Commons Postgres boots cleanly in `larakube-plex` namespace.
- [ ] DB tenant `auto_plex_app_local` is created.
- [ ] Application scaffolding completes successfully.

---

### Test Case 3: Statamic Scaffolding (`statamic:new`)

**Objective**: Verify that `statamic:new` uses the driver compatibility matrix and reuses active Plex Commons services.

#### Test Steps:
1. Execute:
   ```bash
   larakube statamic:new my-statamic-site
   ```
2. Observe the database selection prompt.

#### Expected Results:
- [ ] Prompt options are filtered to supported drivers (**PostgreSQL, MySQL, MariaDB, SQLite**).
- [ ] Active Plex Commons PostgreSQL is pre-selected as the default option.
- [ ] Site scaffolding completes with Octane, Meilisearch, and SeaweedFS S3 configurations.

---

### Test Case 4: Driver Matrix Compatibility Safeguards

**Objective**: Verify that frameworks with restricted DB support (e.g. WordPress) only display compatible database drivers.

#### Test Steps:
1. Run `larakube init` inside a WordPress project or create a WordPress site:
   ```bash
   larakube init --framework=wordpress
   ```

#### Expected Results:
- [ ] Database prompt strictly shows **MySQL** and **MariaDB**.
- [ ] Unsupported drivers (like SQLite or Postgres) are hidden or prevented.

---

### Test Case 5: Automated Verification Suite

**Objective**: Run automated Pest feature tests and static analysis.

#### Commands to Run:
```bash
# 1. Run Pest Feature Test Suite
./php vendor/bin/pest tests/Feature/PlexAutoProvisioningTest.php

# 2. Run PHPStan Static Analysis
./php vendor/bin/phpstan
```

#### Expected Results:
- [ ] `PlexAutoProvisioningTest`: **2 passed (14 assertions)**.
- [ ] `PHPStan`: **[OK] No errors** across 446 files.

---

## 🛠️ Summary Matrix for Teammates

| Scenario | Prior Behavior | **New Behavior** 👑 |
|---|---|---|
| **Multiple Projects** | Separate DB container per project (~500MB RAM each) | **1 Shared Plex DB Container (~150MB total)** |
| **`laravel new` DB Flag** | Hardcoded `--database=sqlite` | **Dynamic `--database=pgsql` (or `mysql`)** |
| **Missing Plex DB** | Manual setup required | **Auto-provisions Plex Commons with spinner** |
| **`.env` Generation** | Overwritten post-scaffold | **Native `.env` generated on step 1** |
| **Driver Selection** | Unfiltered prompt list | **Smart prompt filtered by `AppFramework` matrix** |
