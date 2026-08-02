# Plan: Multi-Framework PHP

## Priority order

1. **WordPress** (Phase 1) — SSU images already support it; image + infra reuse is near-total
2. **Symfony** (Phase 2)
3. **CodeIgniter / CakePHP / generic PHP** (Phase 3)

---

## Phase 1 — WordPress

### Why SSU, not FrankenPHP / frankenwp

LaraKube CLI already uses `serversideup/php` images. The SSU team has a working
WordPress reference at `serversideup/docker-wordpress` and documented guidance at
https://serversideup.net/open-source/docker-php/docs/framework-guides/wordpress/using-wordpress-with-docker.

The only image difference vs Laravel: **`fpm-apache` instead of `fpm-nginx`.**
Apache is required for WordPress because most plugins rely on `.htaccess` rewrite rules.
Everything else — health checks, permission model, env var conventions — is the same SSU
surface LaraKube CLI already knows how to drive.

### What differs from a Laravel deployment

| Concern | Laravel | WordPress |
|---|---|---|
| Base image variant | `fpm-nginx` | `fpm-apache` |
| Document root | `/var/www/html/public` | `/var/www/html` |
| Build step | `composer install` + `npm run build` | none (core pre-installed in image) |
| Migrate command | `php artisan migrate` | `wp db update` (WP-CLI) |
| Queue worker | Horizon / `queue:work` | none (or plugin-based) |
| Scheduler | `php artisan schedule:run` CronJob | `wp cron event run --due-now` CronJob |
| Health check path | `/healthcheck` (SSU built-in) | `/healthcheck` (same SSU built-in) |
| Uploads path | `storage/app/public` → S3 or PVC | `wp-content/uploads` → S3 or PVC |
| Sessions | file / Redis | file / Redis (same) |
| Horizon pod | yes (if enabled) | no |
| Reverb pod | yes (if enabled) | no |

### wp-config.php

WordPress reads DB credentials from `wp-config.php`. In a K8s deployment the file
must read from environment variables (injected via ConfigMap/Secret) rather than
hardcoded values. LaraKube CLI generates a minimal `wp-config.php` wrapper:

```php
define('DB_NAME',     getenv('WORDPRESS_DB_NAME'));
define('DB_USER',     getenv('WORDPRESS_DB_USER'));
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD'));
define('DB_HOST',     getenv('WORDPRESS_DB_HOST'));
define('DB_CHARSET',  'utf8mb4');
define('DB_COLLATE',  '');

$table_prefix = getenv('WORDPRESS_TABLE_PREFIX') ?: 'wp_';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
```

This file is baked into the Docker image at build time. The env vars are sourced
from the K8s Secret (same mechanism as `DB_PASSWORD` in Laravel).

### Uploads / media

`wp-content/uploads` is the uploads directory. Same two options as Laravel storage:

- **Single-node VPS**: PVC mounted at `/var/www/html/wp-content/uploads`
- **Multi-node / Plex**: WP Offload Media plugin pointing to MinIO/SeaweedFS S3 endpoint

LaraKube CLI surfaces this as the same `storage` driver choice it already asks during
`larakube env`. When `storage = seaweedfs` or `storage = minio`, the scaffold adds
`WP_OFFLOAD_MEDIA_*` env vars and instructions to install the plugin.

### WP-Cron → K8s CronJob

WordPress's default WP-Cron fires on page loads (`wp-cron.php` triggered by HTTP).
In a K8s pod this is unreliable. LaraKube CLI disables WP-Cron in the image
(`DISABLE_WP_CRON=true` → `define('DISABLE_WP_CRON', true)` in wp-config.php) and
deploys a K8s CronJob:

```
schedule: "*/5 * * * *"
command: ["wp", "cron", "event", "run", "--due-now", "--path=/var/www/html"]
```

Same pattern as the Laravel scheduler CronJob already in the manifests.

### WP-CLI

WP-CLI (`wp`) must be in the image for the cron CronJob and for `wp db update` on
deploy. The SSU `fpm-apache` image does not include WP-CLI — it is added in the
generated Dockerfile:

```dockerfile
FROM serversideup/php:8.2-fpm-apache

RUN curl -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

COPY wp-config.php /var/www/html/wp-config.php
# WordPress core is downloaded by bundle:build or pre-committed to the repo
```

### Database

MariaDB **or MySQL** (WordPress supports both — official requirements are
"MariaDB 10.11+ or MySQL 8.0+"; Postgres is NOT supported natively). `larakube new
--wordpress` forces `database = mariadb | mysql` and skips the Postgres/MongoDB/SQLite
prompts. Full cross-framework driver matrix: see `wordpress-nextjs-workloads.md` §2b.

### Framework selector

```json
{ "framework": "wordpress" }
```

Set automatically by `larakube new --wordpress`. For existing projects:
`larakube init` now asks "What PHP framework?" with Laravel as the default.

---

## Phase 2 — Symfony

| Concern | Value |
|---|---|
| Base image variant | `fpm-nginx` (same as Laravel) |
| Document root | `public/` |
| Build step | `composer install --no-dev --optimize-autoloader` |
| Migrate command | `php bin/console doctrine:migrations:migrate --no-interaction` |
| Queue worker | `php bin/console messenger:consume async` (configurable transport) |
| Scheduler | `php bin/console scheduler:run` (Symfony 6.3+) or system cron |
| Health check | `/` (or a configurable `/health` endpoint) |

Symfony's Messenger `consume` command needs the transport name as an argument —
stored as `queueWorkerArgs` in `.larakube.json`.

---

## Phase 3 — CodeIgniter / CakePHP / Generic PHP

| Framework | Migrate cmd | Worker | Scheduler |
|---|---|---|---|
| CodeIgniter | `php spark migrate` | none built-in | `php spark schedule:run` |
| CakePHP | `php bin/cake migrations migrate` | none built-in | system cron |
| Generic PHP | none | none | none |
| Yii2 | `php yii migrate` | none built-in | system cron |

All use `fpm-nginx` + `public/` document root (except CakePHP: `webroot/`).

---

## FrameworkDriver enum

A `FrameworkDriver` enum (mirrors DatabaseDriver/CacheDriver/StorageDriver):

```php
enum FrameworkDriver: string
{
    case Laravel     = 'laravel';
    case WordPress   = 'wordpress';
    case Symfony     = 'symfony';
    case CodeIgniter = 'codeigniter';
    case CakePHP     = 'cakephp';
    case GenericPHP  = 'php';

    public function imageVariant(): string { ... }    // fpm-nginx vs fpm-apache
    public function documentRoot(): string { ... }
    public function buildCommand(): ?string { ... }   // null = no build step
    public function migrateCommand(): ?string { ... } // null = no migrations
    public function queueWorkerCommand(): ?string { ... }
    public function schedulerCommand(): ?string { ... }
    public function healthCheckPath(): string { ... }
    public function hasHorizon(): bool  { return $this === self::Laravel; }
    public function hasReverb(): bool   { return $this === self::Laravel; }
    public function requiresWpCli(): bool { return $this === self::WordPress; }
    public function forceMariaDb(): bool  { return $this === self::WordPress; }
}
```

All Blade templates for the web Deployment, migrate init container, queue worker
Deployment, and scheduler CronJob call into `FrameworkDriver` — zero hardcoded
framework checks in templates.

---

## Branding / positioning

The tagline shifts from "Kubernetes for Laravel" to **"Kubernetes for PHP"**.
Laravel-specific features (Horizon, Reverb, Octane, Plex) remain Laravel-only —
they are not removed or diluted. WordPress-specific features (WP-CLI, WP-Cron
replacement, fpm-apache, wp-config.php generation) are WordPress-only.

---

## Open questions

- `larakube new` prompt order: keep Laravel as the first/default option; show other
  frameworks with a "More frameworks →" hint. Don't overwhelm new Laravel users.
- WordPress multisite: out of scope for Phase 1. Note in docs that multisite requires
  a different Traefik routing setup and a dedicated plan.
- Plugin management: document that plugins must be committed to the image or managed
  via `wp-content` PVC — not via the WP admin UI (overwrites on pod restart unless
  PVC is mounted at `wp-content/plugins` too).
