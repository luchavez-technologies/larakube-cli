# Plan: First-Class TanStack Start 1.0 Support in LaraKube (CLI & Desktop)

**Status:** High Priority / Ready for Implementation  
**Created:** 2026-10-15  
**Target:** `cli` & `desktop` (`develop` branch)

---

## 🎯 Vision & Value Proposition

**TanStack Start 1.0** has officially reached stable status. Built by Tanner Linsley and the TanStack team on top of **TanStack Router**, **Vite**, and **UnJS Nitro**, it is the premier modern, un-opinionated alternative to Next.js and Remix.

### Why TanStack Start is a Natural Fit for LaraKube
1. **Shared Vite Ecosystem**: Modern Laravel is standardized on Vite. TanStack Start is built natively on Vite (with Rsbuild support). Scaffolding a full-stack TanStack Start application or pairing a Laravel API with a TanStack Start frontend creates unified build tooling, HMR, and developer mental models.
2. **Lean Nitro Containerization**: Unlike Next.js (which requires complex standalone traces, Sharp configurations, and large node_modules footprints), TanStack Start builds via **Nitro** directly into a self-contained `.output/` bundle. **Production containers require zero runtime `node_modules`**, producing lightweight images (< 150 MB) with ultra-low idle memory (~60–120 MB RAM).
3. **100% Type-Safe Architecture**: End-to-end type safety across route loaders, search params, and server RPC functions (`createServerFn()`).
4. **Plex Commons Synergy**: Server functions, Prisma, or Drizzle ORM easily bind to shared Plex Commons PostgreSQL/MySQL, while Nitro leverages Plex Commons Redis for caching and session storage.

---

## 🧭 Architectural Mapping

| Dimension | Specification |
| :--- | :--- |
| **Framework Enum** | `AppFramework::TANSTACK_START` (`'tanstack'`) |
| **Category** | `'fullstack'` (with visibility in `'frontend'`) |
| **Runtime Engine** | Node 20/22 / UnJS Nitro Server |
| **Container Port** | `3000` (`PORT=3000`) |
| **Dev Ports** | `3000` (Dev server with Vite HMR) |
| **Dev Command** | `npm run dev` |
| **Build Command** | `npm run build` (outputs `.output/`) |
| **Run Command** | `node .output/server/index.mjs` |
| **Workspace Toolchain** | `WorkspaceRuntime::NODE` |
| **Health Probe** | `GET /` on port `3000` (initialDelay: 10s, period: 10s) |

---

## 🧱 Implementation Phases

### Phase 1: Core CLI Framework Definition (`cli`)

#### 1.1 `AppFramework` Enum Updates (`cli/app/Enums/AppFramework.php`)
- Add `case TANSTACK_START = 'tanstack';`
- Label: `'TanStack Start'`
- Description: `'Full-stack React framework powered by TanStack Router, Vite, and Nitro.'`
- Category: `'fullstack'`
- Tech badge: `'React'`
- Scaffold command: `'tanstack:new'`
- Container port: `3000`
- Dev ports: `[['name' => 'App', 'port' => 3000]]`
- Dev command: `'npm run dev'`
- Runtime: `WorkspaceRuntime::NODE`
- Flags: `isServerApp() === true`, `usesNpm() === true`

#### 1.2 Nitro Dockerfile Template (`cli/resources/views/docker/tanstack.blade.php`)
Multi-stage build compiling directly to Nitro `.output`:
```dockerfile
# Build Stage
FROM docker.io/library/node:22-alpine AS builder
WORKDIR /app
COPY package*.json pnpm-lock.yaml* yarn.lock* ./
RUN if [ -f pnpm-lock.yaml ]; then corepack enable && pnpm install --frozen-lockfile; \
    elif [ -f yarn.lock ]; then corepack enable && yarn install --frozen-lockfile; \
    elif [ -f package-lock.json ]; then npm ci; \
    else npm install; fi
COPY . .
RUN if [ -f pnpm-lock.yaml ]; then pnpm run build; \
    elif [ -f yarn.lock ]; then yarn run build; \
    else npm run build; fi

# Production Runner Stage (No node_modules required!)
FROM docker.io/library/node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV PORT=3000
RUN addgroup -S -g 1001 nodejs && adduser -S -u 1001 -G nodejs nodejs
COPY --from=builder --chown=nodejs:nodejs /app/.output ./.output
USER nodejs
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
```

#### 1.3 Kubernetes Manifest Generation (`cli/app/Traits/GeneratesProjectInfrastructure.php`)
- Add `generateTanstackManifests(ConfigData $config)`:
  - Guard in `generateK8sManifests()`:
    ```php
    if ($config->framework === AppFramework::TANSTACK_START) {
        $this->generateTanstackManifests($config);
        return;
    }
    ```
- Manifest Templates (`cli/resources/views/k8s/tanstack/`):
  - `deployment.blade.php`: Replicas, rolling update, port 3000, liveness/readiness probes, securityContext.
  - `service.blade.php`: ClusterIP on port 3000.
  - `ingress.blade.php`: Traefik ingress route with WebSocket support for streaming & SSR.
  - `configmap.blade.php`: `NODE_ENV=production`, `PORT=3000`, `NITRO_PORT=3000`.
  - `secrets.blade.php`: Secret env vars from OpenBao/Plex Commons.

#### 1.4 Scaffolding Command (`cli/app/Commands/Tanstack/TanstackNewCommand.php`)
- Signature:
  ```php
  protected $signature = 'tanstack:new
                          {name? : The name of the TanStack Start application}
                          {--fast : Skip wizard and use ideal defaults}
                          {--no-plex : Skip Plex Commons auto-provisioning}';
  ```
- Runner executes inside `node:24-alpine` container using official template:
  `npm create @tanstack/start@latest <name> -- --template basic`
- Writes `.larakube.json`, Dockerfile, `.dockerignore`, and Kubernetes overlays.

#### 1.5 Framework Catalog (`cli/app/Services/Scaffolding/FrameworkCatalog.php`)
- Register `AppFramework::TANSTACK_START` in `describe()`.
- Add customizable options:
  - Package manager: `npm` | `pnpm` | `bun`
  - Database: `postgres` | `mysql` | `none` (with Plex Commons autowire)
  - Cache: `redis` | `none`

---

### Phase 2: Desktop Integration (`desktop`)

#### 2.1 Brand Identity & Icons (`desktop/resources/js/components/framework-logo.tsx`)
- Add TanStack visual styling:
  ```tsx
  case 'tanstack':
  case 'tanstackstart':
  case 'start':
      return {
          icon: <SiTanstack size={px} /> /* or custom SVG TanStack ring mark */,
          containerClass: 'bg-[#FF4154]/10 text-[#FF4154] border-[#FF4154]/25',
      };
  ```

#### 2.2 Framework Badges & Cards
- Add `'tanstack'` to [`framework-badge.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/components/framework-badge.tsx).
- Display in [`QuickLaunchModal`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/components/quick-launch-modal.tsx) under "Full Stack".

#### 2.3 Local Development & Workspace Wiring
- Automatically register dev port `3000` with status monitoring.
- Forward DevBox WebSocket tunnel for instant Vite HMR.

---

### Phase 3: Plex Commons & Multi-App Pairing

#### 3.1 Plex Commons Auto-Provisioning
- When scaffolding with default settings:
  - Provisions a tenant in `larakube-plex`.
  - Injects `DATABASE_URL` for PostgreSQL/MySQL.
  - Injects `REDIS_URL` for Nitro cache storage.

#### 3.2 Full-Stack Laravel + TanStack Start Monorepo / Pair Pattern
- Support routing pattern where:
  - `api.<domain>` or `<domain>/api/*` routes to Laravel container.
  - `<domain>/*` routes to TanStack Start container.

---

## 🧪 Verification & Testing Plan

1. **Unit & Feature Tests (`cli`)**:
   - `tests/Feature/Scaffolding/TanstackNewTest.php`: Assert scaffolding command runs, generates `.larakube.json`, Dockerfile, and K8s manifests.
   - `tests/Unit/AppFrameworkTest.php`: Validate enum cases, ports, dev commands, and runtime mappings.
2. **Linting & Analysis**:
   - Run `composer format` (Rector + Pint).
   - Run `composer analyse` (PHPStan level 8).
   - Run `composer test` (Pest parallel).
3. **Desktop Verification**:
   - Run `npm run check` and `npm run types:check`.
   - Verify modal rendering in `QuickLaunchModal`.
4. **Container Build Verification**:
   - Build test image with rootless Podman/Docker:
     `podman build -t tanstack-demo -f Dockerfile .`
   - Verify image size is < 150 MB and boots with `node .output/server/index.mjs`.

---

## 🚀 Execution Order
1. [ ] **Step 1**: Register `AppFramework::TANSTACK_START` in `cli/app/Enums/AppFramework.php`.
2. [ ] **Step 2**: Create Dockerfile template `cli/resources/views/docker/tanstack.blade.php`.
3. [ ] **Step 3**: Create Kubernetes manifest templates in `cli/resources/views/k8s/tanstack/`.
4. [ ] **Step 4**: Implement `TanstackNewCommand.php` in `cli/app/Commands/Tanstack/`.
5. [ ] **Step 5**: Update `FrameworkCatalog.php` in `cli`.
6. [ ] **Step 6**: Add TanStack brand visuals to Desktop (`framework-logo.tsx`, `framework-badge.tsx`).
7. [ ] **Step 7**: Run full test suite in `cli` and `desktop`.
