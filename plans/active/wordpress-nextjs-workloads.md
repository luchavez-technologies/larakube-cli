# Plan: Multi-Platform Application Scaffolding (`statamic:new`, `wordpress:new`, `nextjs:new`)

> **Status:** Completed  
> **Created:** 2026-07-29  
> **Revised:** 2026-08-03 (command verbs updated to `*:new` per user requirement)  
> **Target Version:** LaraKube CLI v1.2.0

> [!IMPORTANT]
> This plan **supersedes and replaces** `multi-framework-php.md`.

---

## 0. Decision Log (from `/grill-me` session, 2026-08-03)

| Question | Decision |
|---|---|
| Command verbs | **`*:new`** (`statamic:new`, `wordpress:new`, `nextjs:new`) — consistent with `laravel new` / `larakube new` |
| Statamic installer | `composer create-project statamic/statamic` (no extra global tool) |
| Statamic DB mode | **Database mode only** — always requires a DatabaseDriver (MySQL/PostgreSQL/MariaDB) |
| WordPress scaffolder | **Bedrock** (`composer create-project roots/bedrock`) — 12-factor, Composer-managed |
| WordPress media offload | **Mandatory S3 wiring** to the project's StorageDriver (MinIO/SeaweedFS/Garage) |
| Next.js output mode | **Standalone** + Redis cache handler wired to CacheDriver (Redis) |
| Command organization | Brand-new isolated command groups: `app/Commands/Statamic/`, `app/Commands/Wordpress/`, `app/Commands/Nextjs/` |
| Blueprint enum cleanup | Remove `Blueprint::STATAMIC` entirely — `statamic:new` owns all its scaffolding logic |
| K8s manifest strategy | Dedicated Blade view namespaces: `k8s.statamic.*`, `k8s.wordpress.*`, `k8s.nextjs.*` |
| Implementation sequence | Phase 1: `statamic:new` → Phase 2: `wordpress:new` → Phase 3: `nextjs:new` |
| Statamic base image | SSU `fpm-nginx` (same image vendor as Laravel) with PHP version selection surfaced to user |
| WordPress base image | SSU `fpm-nginx` via Bedrock (Nginx-native; no `.htaccess` needed) |

---

## 1. Strategic Rationale

### Why `*:new` naming

1. `larakube new` scaffolds a new Laravel application. Using `statamic:new`, `wordpress:new`, and `nextjs:new` extends this scaffolding convention to external platforms cleanly.
2. Statamic, WordPress, and Next.js are **application scaffolding targets**, matching the `*:new` action verb.
3. Statamic is separated from `larakube new` specifically because it has its own installer cadence — keeping it isolated prevents maintenance debt.

### Expanded Polyglot Roadmap
Django, FastAPI, Spring Boot, and .NET Core are covered under the subsequent plan: `cli/plans/active/polyglot-workloads-python-java-dotnet.md`.

---

## 2. Driver Compatibility Matrix (Live — Verified 2026-08-03)

### 2a. DatabaseDriver

| Driver | Statamic | WordPress (Bedrock) | Next.js |
|---|---|---|---|
| `mysql` (8.4) | ✅ (Eloquent) | ✅ Required | ✅ (Prisma/Drizzle) |
| `mariadb` (11.8) | ✅ (Eloquent) | ✅ Required | ✅ (Prisma/Drizzle) |
| `postgresql` | ✅ (Eloquent) | ❌ Not officially supported | ✅ (Prisma/Drizzle) |
| `mongodb` | ❌ | ❌ | ✅ (Mongoose) |
| `sqlite` | ❌ (Database mode requires a real DB server) | ❌ | ⚠️ (Prisma only, not idiomatic) |

**Implementation rules:**
- `statamic:new`: Present MySQL, MariaDB, PostgreSQL. Hide MongoDB and SQLite.
- `wordpress:new`: Present **MySQL and MariaDB only**. PostgreSQL not officially supported by WP core. SQLite and MongoDB hidden.
- `nextjs:new`: Present MySQL, MariaDB, PostgreSQL. Hide SQLite (not idiomatic) and MongoDB.

### 2b. CacheDriver

| Driver | Statamic | WordPress (Bedrock) | Next.js |
|---|---|---|---|
| `redis` | ✅ (Laravel `cache.php`) | ✅ (`humanmade/wp-redis` drop-in) | ✅ **Required** — distributed ISR/RSC caching |
| `memcached` | ✅ (Laravel `cache.php`) | ✅ (W3TC drop-in) | ⚠️ (not idiomatic for Next.js ISR) |
| `database` | ✅ (Laravel cache table) | ❌ (WP transients use `wp_options` — no equivalent) | ❌ |

**Implementation rules:**
- `statamic:new`: All three CacheDriver options available (it's a Laravel app).
- `wordpress:new`: Hide `database` CacheDriver. Present Redis (recommended) and Memcached.
- `nextjs:new`: **Only Redis** — mandatory for distributed ISR/RSC cache across replicas. Memcached and database hidden.

### 2c. SearchDriver

| Driver | Statamic | WordPress (Bedrock) | Next.js |
|---|---|---|---|
| `meilisearch` | ✅ (via Scout + statamic/meilisearch) | ❌ No maintained official plugin | ✅ `meilisearch-js` + `instant-meilisearch` |
| `typesense` | ✅ (via Scout + typesense/laravel-scout-typesense) | ✅ "Search with Typesense" plugin | ✅ `typesense-js` + adapter |
| `database` | ✅ (Scout DB driver) | ❌ (WP_Query LIKE is native) | ❌ |

**Implementation rules:**
- `statamic:new`: Present all three SearchDriver options (Scout works with Statamic).
- `wordpress:new`: Skip Scout prompt. If search driver selected, provision deployment + print plugin install instructions.
- `nextjs:new`: Present Meilisearch and Typesense. Hide `database` SearchDriver.

### 2d. StorageDriver

All three S3-compatible drivers are valid for all three platforms:

| Driver | Statamic | WordPress (Bedrock) | Next.js |
|---|---|---|---|
| `minio` | ✅ | ✅ **Mandatory** — `humanmade/s3-uploads` | ✅ |
| `seaweedfs` | ✅ | ✅ **Mandatory** | ✅ |
| `garage` | ✅ | ✅ **Mandatory** | ✅ |

---

## 3. Phase 1 — `statamic:new`

### 3a. Official install path
Uses **`composer create-project statamic/statamic`** run inside SSU Docker container.

### 3b. Infrastructure
- **Command signature**: `statamic:new {name?} {--fast}`
- **Base image**: `serversideup/php:{version}-fpm-nginx`
- **Document root**: `/var/www/html/public`
- **Health check**: `/up`

### 3c. Files Created
- `app/Commands/Statamic/StatamicNewCommand.php`
- `resources/views/k8s/statamic/deployment.blade.php`
- `resources/views/k8s/statamic/ingress.blade.php`
- `tests/Feature/StatamicNewCommandTest.php`

---

## 4. Phase 2 — `wordpress:new`

### 4a. Official install path
Uses **`composer create-project roots/bedrock`** run inside SSU Docker container.

### 4b. Infrastructure
- **Command signature**: `wordpress:new {name?} {--fast}`
- **Base image**: `serversideup/php:{version}-fpm-nginx`
- **Document root**: `/var/www/html/web`
- **WP-Cron**: Disabled (`DISABLE_WP_CRON=true`) → K8s CronJob (`*/5 * * * *` → `wp cron event run --due-now`)
- **Health check**: `/wp-includes/version.php`

### 4c. Files Created
- `app/Commands/Wordpress/WordpressNewCommand.php`
- `resources/views/k8s/wordpress/deployment.blade.php`
- `resources/views/k8s/wordpress/ingress.blade.php`
- `resources/views/k8s/wordpress/cron.blade.php`
- `resources/views/k8s/wordpress/migrate-init-container.blade.php`
- `tests/Feature/WordpressNewCommandTest.php`

---

## 5. Phase 3 — `nextjs:new`

### 5a. Official install path
Uses `npx create-next-app@latest` inside Node.js 22 Alpine Docker container.

### 5b. Infrastructure
- **Command signature**: `nextjs:new {name?} {--fast}`
- **Runtime**: Node.js 22 LTS (Alpine base image)
- **Output mode**: `standalone` (auto-patched into `next.config.ts`)
- **Cache**: Redis mandatory (`@neshca/cache-handler`)
- **Health check**: `GET /api/health` — generated route handler at `app/api/health/route.ts`
- **Migrations**: `npx prisma migrate deploy` init container

### 5c. Files Created
- `app/Commands/Nextjs/NextjsNewCommand.php`
- `resources/views/k8s/nextjs/deployment.blade.php`
- `resources/views/k8s/nextjs/ingress.blade.php`
- `resources/views/k8s/nextjs/migrate-init-container.blade.php`
- `tests/Feature/NextjsNewCommandTest.php`

---

## 6. Shared Architecture Summary

- `AppFramework` enum created (`app/Enums/AppFramework.php`).
- `Blueprint::STATAMIC` removed from `app/Enums/Blueprint.php`.
- `ConfigData::$framework` property added.
- All commands, view templates, and Pest test suites implemented and passing (`pint`, `phpstan`, `pest`).
