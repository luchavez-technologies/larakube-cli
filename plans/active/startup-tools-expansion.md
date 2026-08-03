# Startup OS Tools Expansion (`design:init` Penpot & `api:init` Hoppscotch) Plan

**Status:** Draft / Active (6 tools shipped; `design:init` & `api:init` in progress)
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

### CLI Command (`larakube design:init`)

- **Enum**: `ClusterTool::DESIGN = 'design'`, `SharedClusterService::DESIGN = 'design'`
- **Host**: `design.{domain}` (e.g., `design.dev.test`)
- **Bucket**: `commonsBuckets() => ['design-assets']`
- **SSO Wiring**: `sso:wire <env> --tool=design` sets `PENPOT_OIDC_CLIENT_ID`, `PENPOT_OIDC_CLIENT_SECRET`, `PENPOT_OIDC_URI_ISSUER`

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

### CLI Command (`larakube api:init`)

- **Enum**: `ClusterTool::API = 'api'`, `SharedClusterService::API = 'api'`
- **Host**: `api.{domain}` (e.g., `api.dev.test`)
- **ForwardAuth SSO**: `usesForwardAuth() => true` enables `larakube sso:wire <env> --tool=api`
- **CI/CD Integration**: Project pipelines can execute API collection test suites via CLI test runners (`npx @usebruno/cli run` or `hoppscotch-cli`)

---

## Implementation Tasks

- [ ] Add `ClusterTool::DESIGN = 'design'` and `SharedClusterService::DESIGN = 'design'`
- [ ] Add `ClusterTool::API = 'api'` and `SharedClusterService::API = 'api'`
- [ ] Implement `app/Commands/Design/DesignInitCommand.php` (`larakube design:init`)
- [ ] Implement `app/Commands/Api/ApiInitCommand.php` (`larakube api:init`)
- [ ] Create Blade templates for `k8s.design.*` and `k8s.api.*`
- [ ] Create Pest feature tests: `tests/Feature/DesignInitCommandTest.php` and `tests/Feature/ApiInitCommandTest.php`
- [ ] Format with `./php vendor/bin/pint` and verify PHPStan (0 errors)
