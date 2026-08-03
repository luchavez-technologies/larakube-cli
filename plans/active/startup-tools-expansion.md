# Startup OS Tools Expansion (`draw:init`) Plan

**Status:** Draft / Active (6 of 7 tools shipped, `draw:init` remaining)
**Created:** 2026-07-29
**Updated:** 2026-08-03
**Target Version:** LaraKube CLI v1.2.0

---

## Executive Summary

LaraKube's "Startup OS" suite provides 7 lightweight, production-ready open-source tools designed to run within 4GB VPS server constraints by sharing Plex Commons infrastructure (Postgres, Redis, SeaweedFS/MinIO).

### Status of the 7 Target Tools:

| # | Tool | Product | CLI Command | Status | Storage / DB Backend |
|---|------|---------|-------------|--------|----------------------|
| 1 | `analytics` | Umami | `larakube analytics:init` | ✅ **Shipped** | Plex Commons Postgres |
| 2 | `tasks` | Planka / Plane | `larakube tasks:init` | ✅ **Shipped** | Plex Commons Postgres + Redis |
| 3 | `sign` | Documenso | `larakube sign:init` | ✅ **Shipped** | Plex Commons Postgres + S3 |
| 4 | `support` | Chatwoot | `larakube support:init` | ✅ **Shipped** | Plex Commons Postgres + Redis |
| 5 | `link` | Kutt | `larakube link:init` | ✅ **Shipped** | Plex Commons Postgres + Redis |
| 6 | `crm` | Twenty | `larakube crm:init` | ✅ **Shipped** | Plex Commons Postgres |
| 7 | `draw` | Excalidraw | `larakube draw:init` | ⏳ **In Progress** | Plex Commons S3 (`draw-storage` bucket) |

---

## Final Remaining Component: `draw:init` (Excalidraw)

### 🎯 Objective

Deploy a collaborative, S3-backed Excalidraw whiteboarding platform (`draw.{domain}`) into `larakube-shared`.

By default, official Excalidraw stores diagrams only in browser `localStorage`. `draw:init` connects Excalidraw to a backend storage microservice backed by Plex Commons S3 (`seaweedfs` / `minio` / `garage`) so teams can save, share, and collaborate on architecture diagrams persistently across sessions.

---

## Architecture & Topology

```
┌─────────────────────────────────────────────────────────────┐
│                    larakube-shared namespace                │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │         Traefik IngressRoute (draw.{domain})         │   │
│  │   /           → excalidraw (Frontend SPA)            │   │
│  │   /api/v1/    → excalidraw-storage (S3 backend)     │   │
│  │   /socket.io/ → excalidraw-room (Collab WebSocket) │   │
│  │   Optional Middleware: ForwardAuth (Zitadel SSO)     │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌──────────────────┐   ┌──────────────────────────────┐    │
│  │    excalidraw    │   │      excalidraw-storage      │    │
│  │  (Frontend SPA)  │   │  (S3 Diagram Storage Backend)│    │
│  │     ~30Mi RAM    │   │          ~50Mi RAM           │    │
│  └──────────────────┘   └──────────────┬───────────────┘    │
│                                        │                    │
│                         ┌──────────────▼───────────────┐    │
│                         │     Plex Commons S3 (S3)     │    │
│                         │    Bucket: `draw-storage`    │    │
│                         └──────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

---

## Implementation Details

### Step 1: Enum Expansion (`App\Enums\ClusterTool` & `SharedClusterService`)

- [ ] Add `ClusterTool::DRAW = 'draw'` case to `ClusterTool.php`
- [ ] Label: `Collaborative Whiteboard (Excalidraw)`
- [ ] Namespace: `larakube-shared`
- [ ] Deployment: `draw-excalidraw`
- [ ] Plex Commons Bucket: `commonsBuckets() => ['draw-storage']`
- [ ] ForwardAuth SSO support: `usesForwardAuth() => true`
- [ ] Add `SharedClusterService::DRAW = 'draw'` to `SharedClusterService.php`
  - Host prefix: `draw`
  - Template: `k8s.draw.ingress`

### Step 2: Command Class (`app/Commands/Draw/DrawInitCommand.php`)

- [ ] Implement `DrawInitCommand` extending standard command pattern
- [ ] Traits: `DeploysClusterTool`, `InteractsWithClusterContext`, `InteractsWithPlex`, `LaraKubeOutput`, `ResolvesToolEnvironment`, `SyncsClusterSecrets`
- [ ] Demand-bootstrap S3 via `$this->ensureCommons([$s3Service])`
- [ ] Allocate bucket `draw-storage` via `$this->allocateStorageBucket($s3Driver, 'draw-storage')`
- [ ] Deploy K8s workload and report access URL (`https://draw.dev.test`)

### Step 3: Kubernetes Views (`resources/views/k8s/draw/`)

- [ ] `resources/views/k8s/draw/shared.blade.php` — Deployment, Service, ConfigMap for Excalidraw frontend + storage backend
- [ ] `resources/views/k8s/draw/ingress.blade.php` — Traefik IngressRoute with path routing (`/`, `/api/v1/`, `/socket.io/`)

### Step 4: SSO & VPN Wiring

- [ ] `sso:wire <env> --tool=draw` — Attaches Zitadel ForwardAuth middleware to `draw.{domain}`
- [ ] `--vpn-only` — Applies NetBird IP whitelist middleware

---

## Resource Footprint (4GB Server Constraints)

| Component | RAM | CPU | Storage |
|-----------|-----|-----|---------|
| Excalidraw Frontend | ~30Mi | ~0.05 core | — |
| Excalidraw Storage Backend | ~50Mi | ~0.05 core | Plex Commons S3 (`draw-storage`) |
| **Total** | **~80Mi** | **~0.1 core** | Shared S3 |

> [!TIP]
> At ~80Mi RAM total, Excalidraw is extremely lightweight and easily fits on a $12/mo 2GB VPS alongside the existing companion suite.

---

## Implementation Tasks & Verification

- [ ] Add `ClusterTool::DRAW` and `SharedClusterService::DRAW` cases
- [ ] Create `DrawInitCommand.php` (`larakube draw:init`)
- [ ] Create Blade templates `k8s.draw.shared` and `k8s.draw.ingress`
- [ ] Create Pest feature test: `tests/Feature/DrawInitCommandTest.php`
- [ ] Format code with `./php vendor/bin/pint`
- [ ] Verify static analysis with `./php vendor/bin/phpstan` (0 errors)
- [ ] Run Pest test suite: `./php vendor/bin/pest tests/Feature/DrawInitCommandTest.php`
