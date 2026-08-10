# Startup OS Tools Expansion (`design:init` Penpot & `api:init` Hoppscotch) Plan

**Status:** 🟡 PARTIAL — verified 2026-08-08. The shipped tools are real, but "`design:init` & `api:init` in progress" was not accurate: neither command exists in the CLI's 238-command surface, and neither has a `ClusterTool` case. They are ⛔ not started. Everything else here has landed.
**Created:** 2026-07-29
**Updated:** 2026-08-03 (post grill-me gap analysis)
**Target Version:** LaraKube CLI v1.2.0

---

## Executive Summary

LaraKube's "Startup OS" suite provides production-ready open-source tools designed to run within 4GB VPS server constraints by sharing Plex Commons infrastructure (Postgres, Redis, SeaweedFS/MinIO/Garage).

### Suite Status:

| # | Tool Slug | Product | CLI Command | Status | Storage / DB Backend | SSO Strategy |
|---|-----------|---------|-------------|--------|----------------------|--------------|
| 1 | `analytics` | Umami | `larakube analytics:init` | ✅ **Shipped** | Plex Commons Postgres | Native OIDC |
| 2 | `tasks` | Planka / Plane | `larakube tasks:init` | ✅ **Shipped** | Plex Commons Postgres + Redis | Native OIDC |
| 3 | `sign` | Documenso | `larakube sign:init` | ✅ **Shipped** | Plex Commons Postgres + S3 | Native OIDC |
| 4 | `support` | Chatwoot | `larakube support:init` | ✅ **Shipped** | Plex Commons Postgres + Redis | Native OIDC |
| 5 | `link` | Kutt | `larakube link:init` | ✅ **Shipped** | Plex Commons Postgres + Redis | Native OIDC |
| 6 | `crm` | Twenty | `larakube crm:init` | ✅ **Shipped** | Plex Commons Postgres | Native OIDC |
| 7 | `design` | Penpot | `larakube design:init` | ⏳ **Target v1.2** | Plex Commons Postgres + Redis + S3 (`design-assets`) | Native FREE OIDC |
| 8 | `api` | Hoppscotch | `larakube api:init` | ⏳ **Target v1.2** | Plex Commons Postgres + Redis | Traefik ForwardAuth |

---

## Component 1: `design:init` — Penpot (Figma & Canva Alternative)

### 🎯 Objective

Deploy **Penpot** — the leading open-source design, prototyping, and whiteboarding platform — at `design.{domain}` into `larakube-shared`.

- **Real-time Collaboration**: Multi-user live editing, SVG native, design tokens, component libraries.
- **Zitadel SSO**: Native OpenID Connect (OIDC) SSO supported out-of-the-box in Penpot's free edition via `PENPOT_OIDC_*` env vars.
- **Resource Optimization**: Reuses Plex Commons Postgres, Redis, and S3 (`seaweedfs`/`minio`/`garage`) for asset storage, running in ~500–600MB RAM.

### Topology:

```
┌─────────────────────────────────────────────────────────────┐
│                    larakube-shared namespace                │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │        Traefik IngressRoute (design.{domain})        │   │
│  │   / → penpot-frontend (SPA)                          │   │
│  │   /api/* → penpot-backend (Clojure JVM)              │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌──────────────────┐   ┌──────────────────────────────┐    │
│  │ penpot-frontend  │   │        penpot-backend        │    │
│  │  (Nginx SPA)     │   │     (Clojure JVM ~400MB)     │    │
│  │    ~20MB RAM     │   │  Native OIDC SSO (Zitadel)   │    │
│  └──────────────────┘   └──────────┬────────┬──────────┘    │
│                                    │        │               │
│             ┌──────────────────────┴┐     ┌─┴────────────┐  │
│             │  Plex Commons DB/Cache│     │Commons S3 (S3)│ │
│             │ Postgres + Redis/Valkey     │`design-assets`│ │
│             └───────────────────────┘     └──────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

### CLI Commands

- `larakube design:init` — Deploys Penpot into `larakube-shared`
- `larakube design:show` — Displays Penpot deployment status, host, and database details
- `larakube design:remove` — Teardowns Penpot workload and drops `penpot` database from Plex Commons

---

## Component 2: `api:init` — Hoppscotch (Postman Alternative Web App)

### 🎯 Objective

Deploy **Hoppscotch** — the web-first open-source API development and testing suite — at `api.{domain}` into `larakube-shared`.

- **Web UI & Centralized Workspaces**: Teammates visit `https://api.{domain}` in their browser, log in, create collection workspaces, and test APIs together.
- **FREE SSO via Traefik ForwardAuth**: Hoppscotch Community Edition paywalls native OIDC SSO. LaraKube solves this by setting `usesForwardAuth() => true`, wrapping `api.{domain}` with Zitadel ForwardAuth at the Traefik proxy boundary for 100% FREE SSO protection!
- **CORS Browser Extension**: Bundles configuration pointers for Hoppscotch's browser extension to bypass CORS seamlessly for in-browser requests.
- **Resource Optimization**: Reuses Plex Commons Postgres and Redis to store workspaces and environments (~150MB RAM).

### Topology:

```
┌─────────────────────────────────────────────────────────────┐
│                    larakube-shared namespace                │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │         Traefik IngressRoute (api.{domain})          │   │
│  │   / → hoppscotch-app (Web UI)                        │   │
│  │   /backend/* → hoppscotch-backend                    │   │
│  │   Middleware: Zitadel ForwardAuth (Proxy-level SSO)  │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌──────────────────┐   ┌──────────────────────────────┐    │
│  │  hoppscotch-app  │   │      hoppscotch-backend      │    │
│  │    (Nginx PWA)   │   │       (Node.js ~100MB)       │    │
│  │    ~20MB RAM     │   │    Stores Workspaces & Envs  │    │
│  └──────────────────┘   └──────────────┬───────────────┘    │
│                                        │                    │
│                         ┌──────────────▼───────────────┐    │
│                         │     Plex Commons DB/Cache    │    │
│                         │   PostgreSQL + Redis/Valkey  │    │
│                         └──────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

### CLI Commands (`larakube api:*`)

- **Enum**: `ClusterTool::API = 'api'`, `SharedClusterService::API = 'api'`
- **Host**: `api.{domain}` (e.g., `api.dev.test`)
- **ForwardAuth SSO**: `usesForwardAuth() => true` enables `larakube sso:wire <env> --tool=api`
- **CI/CD Integration**: Project pipelines can execute API collection test suites via CLI test runners (`npx @usebruno/cli run` or `hoppscotch-cli`)
- `larakube api:init` — Deploys Hoppscotch into `larakube-shared`
- `larakube api:show` — Displays Hoppscotch deployment status, host, and database details
- `larakube api:remove` — Teardowns Hoppscotch workload and drops `hoppscotch` database from Plex Commons

---

## 📧 Email Wiring (`mail:wire`) Integration

Both Penpot and Hoppscotch send emails (team invites, password resets, notification alerts). They implement `smtpEnv()` in `ClusterTool.php`:

```php
// Penpot smtpEnv
self::DESIGN => [
    'deployment' => 'design-penpot-backend',
    'namespace' => $this->namespace(),
    'secret' => 'design-penpot-smtp',
    'vars' => [
        'host' => 'PENPOT_SMTP_DEFAULT_FROM',
        'port' => 'PENPOT_SMTP_SERVER_PORT',
        'user' => 'PENPOT_SMTP_USERNAME',
        'password' => 'PENPOT_SMTP_PASSWORD',
        'from' => 'PENPOT_SMTP_DEFAULT_FROM',
    ],
],

// Hoppscotch smtpEnv
self::API => [
    'deployment' => 'hoppscotch-backend',
    'namespace' => 'larakube-shared',
    'secret' => 'hoppscotch-smtp',
    'vars' => [
        'host' => 'MAILER_SMTP_URL',
        'from' => 'MAILER_ADDRESS_FROM',
    ],
],
```

Executing `larakube mail:wire design` or `larakube mail:wire api` automatically connects them to Stalwart Mail Server!

---

## 🪪 Identity & SSO Wiring (`sso:wire`) Integration

Penpot Community Edition includes native OIDC SSO. `ClusterTool::DESIGN` defines `oidcEnv()`:

```php
self::DESIGN => [
    'deployment' => 'design-penpot-backend',
    'namespace' => $this->namespace(),
    'secret' => 'design-penpot-oidc',
    'redirect_path' => '/api/auth/oauth/zitadel/callback',
    'static' => [
        'PENPOT_FLAGS' => 'enable-login-with-oidc',
        'PENPOT_OIDC_NAME' => 'Zitadel SSO',
    ],
    'vars' => [
        'client_id' => 'PENPOT_OIDC_CLIENT_ID',
        'client_secret' => 'PENPOT_OIDC_CLIENT_SECRET',
        'auth_url' => 'PENPOT_OIDC_AUTH_URI',
        'token_url' => 'PENPOT_OIDC_TOKEN_URI',
        'userinfo_url' => 'PENPOT_OIDC_USERINFO_URI',
        'issuer' => 'PENPOT_OIDC_BASE_URI',
    ],
],
```

Executing `larakube sso:wire production --tool=design` registers a dedicated OIDC app in Zitadel and updates `deployment/design-penpot-backend`.

---

## 🔐 OpenBao & Secrets Rotation (`secrets:wire`) Integration

Per the **OpenBao Secrets Prioritization Standard**:
1. When OpenBao is bootstrapped, `design:init` registers static database role credentials (`penpot` role in `plex-postgres`).
2. `ClusterTool::DESIGN->dbSecretRef()` returns `['secret' => 'design-penpot-db', 'namespace' => 'larakube-shared', 'key' => 'password']`.
3. Executing `larakube secrets:wire production --tool=design` hands database password rotation over to OpenBao (7-day automatic rotation via ExternalSecret controller).

---

## 🔑 VPN Restrictions (`vpn:wire`) Integration

`ClusterTool::DESIGN->vpnMiddlewareTarget()` returns `'design'`. Executing `larakube vpn:wire production --tool=design` attaches Traefik NetBird IP allowlist middleware to `design.{domain}`.

---

## 🌐 Multi-Domain & Multi-Instance Architecture

- **Multi-Domain**: Resolved dynamically via `SharedClusterService::DESIGN` with `hostPrefix() => 'design'`, rendering `design.{domain}` (e.g. `design.dev.test` or `design.example.com`).
- **Multi-Instance**: Enabled via `supportsMultipleInstances() => true`. Passing `--instance=team2` creates:
  - Ingress: `design-team2.{domain}`
  - Deployments: `design-penpot-backend-team2`, `design-penpot-frontend-team2`
  - Postgres DB: `penpot_team2`
  - S3 Bucket: `design-assets-team2`

---

## 📊 Memory & Resource Usage Breakdown

| Service / Workload | Runtime Component | RAM Usage | Notes |
|--------------------|-------------------|-----------|-------|
| `penpot-backend` | Clojure JVM (Java 21) | ~350 MB – 450 MB | Core API & real-time WS sync engine |
| `penpot-frontend` | Nginx SPA Container | ~15 MB – 25 MB | Static bundle web server |
| `penpot-exporter` (optional) | Node.js + Playwright | ~150 MB – 250 MB | Headless PDF/PNG export worker |
| **Total Workload Footprint** | | **~365 MB – 725 MB** | Base ~400MB without exporter |
| **Plex Commons Infrastructure** | Postgres, Redis, SeaweedFS | 0 MB additional | Shared instance reuse |

---

## 🐳 Pinned Docker Images

| Tool | Service | Image | Tag | Notes |
|------|---------|-------|-----|-------|
| `design` | Penpot Backend | `penpotapp/backend` | `2.17` | Clojure JVM backend |
| `design` | Penpot Frontend | `penpotapp/frontend` | `2.17` | Nginx SPA frontend |
| `design` | Penpot Exporter | `penpotapp/exporter` | `2.17` | Optional SVG/PDF exporter |
| `api` | Hoppscotch | `hoppscotch/hoppscotch` | `2026.7.0` | Unified web UI + backend |

---

## Implementation Tasks

- [ ] Add `ClusterTool::DESIGN = 'design'` and `SharedClusterService::DESIGN = 'design'`
- [ ] Add `ClusterTool::API = 'api'` and `SharedClusterService::API = 'api'`
- [ ] Add `commonsDatabases()` entries: `penpot` for `DESIGN`, `hoppscotch` for `API`
- [ ] Implement `app/Traits/InteractsWithDesign.php`
- [ ] Implement `app/Commands/Design/DesignInitCommand.php` (`larakube design:init`)
- [ ] Implement `app/Commands/Design/DesignShowCommand.php` (`larakube design:show`)
- [ ] Implement `app/Commands/Design/DesignRemoveCommand.php` (`larakube design:remove`)
- [ ] Implement `app/Commands/Api/ApiInitCommand.php` (`larakube api:init`)
- [ ] Create Blade templates for `k8s.design.*` (`backend`, `frontend`, `ingress`, `exporter`)
- [ ] Create Pest feature tests: `tests/Feature/DesignInitCommandTest.php` and `tests/Feature/ApiInitCommandTest.php`
- [ ] Format with `./php vendor/bin/pint` and verify PHPStan (0 errors)

