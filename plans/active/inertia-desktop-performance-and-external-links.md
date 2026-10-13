# Plan: Inertia v3 Desktop Performance & External Link Overhaul

**Target Area**: `desktop/` (LaraKube NativePHP Desktop App) & Inertia v3 Integration  
**Date**: 2026-10-13  
**Status**: Active  

---

## 1. Problem Statements & Root Cause Analysis

### Problem 1: External Links Reloading Pages & Wiping Form Data
- **Symptom**: Clicking links intended to open the default browser (e.g. Cloudflare API token link, official provider pricing links on "Create Server", workspace URLs) reloads the page in the desktop app and completely erases user input.
- **Root Cause**:
  - In `desktop/resources/js`, external links were implemented using `<Link href={open().url} method="post" data={{ url }}>` or `router.post('/open', { url })`.
  - In Inertia, `method="post"` is an Inertia page visit sending the `X-Inertia` header.
  - `OpenExternalController.php` runs:
    ```php
    Shell::openExternal($validated['url']);
    return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    ```
  - Because Inertia visits do NOT expect raw JSON, `back()` is returned, triggering a full Inertia page reload.
  - Furthermore, `useForm` on `servers/create.tsx` does not specify a remember key, resetting all un-remembered state upon reload.

### Problem 2: 5+ Second Page Transitions & Navigation Delay
- **Symptom**: Navigating between Dashboard, Servers, Projects, Tools, and Mail takes 5+ seconds. The desktop app feels sluggish and unresponsive.
- **Root Cause**:
  1. **Zero Caching in `StackCatalog`**: Every call to `StackCatalog::all()` runs `larakube cloud:stacks --json` and `kubectl config view -o json`. Controllers call `all()` multiple times per request (e.g. `ClusterToolController::entry` runs it, 302 redirects to `index`, which runs it twice more = 3 CLI process spawns!).
  2. **Uncached Subprocesses in `LocalCluster`**: `LocalCluster::detect()` runs `kubectl config view -o json` and probes candidate clusters with `kubectl cluster-info --context=... --request-timeout=2s` synchronously on every dashboard visit.
  3. **Synchronous Readiness Tool Checks in `DashboardController`**: Synchronously runs `ReadinessCheck::tools()` (checking 9 CLI binaries) just to compute `missingToolsCount`.
  4. **Uncached Negative Responses in `ClusterStatus`**: `ClusterStatus::backup()` only cached non-null results. If a cluster had no backup configured, it re-ran `backup:status` (timeout 180s) on every deferred request, tying up NativePHP's single/limited PHP worker threads.
  5. **No Inertia v3 Prefetching or SWR Caching**: Sidebar `<Link>` tags in `app-layout.tsx` had no `prefetch` or `cacheFor` directives. Every click triggered a cold, synchronous roundtrip with nothing showing until after 250ms.
  6. **302 Redirect Loops on Tools, Mail, Plex**: Sidebar links pointed to `/tools`, `/mail`, `/plex`, which executed CLI checks, redirected to `/servers/{server}/*`, which executed CLI checks again.

---

## 2. Solution Architecture

### A. "Computer Use" & Electron Desktop Automation Capabilities
Clarify for the user:
- We do not possess a generic OS-level mouse/keyboard vision driver (like Claude Computer Use API clicking macOS desktop coordinates).
- However, because LaraKube Desktop is an Electron + Chromium app:
  1. When Electron is started or configured with `--remote-debugging-port=9222`, Chrome DevTools Protocol (CDP) / Playwright / Puppeteer can connect directly to inspect DOM elements, click buttons, fill inputs, read console logs, and take screenshots.
  2. We have direct runtime access to the local SQLite database (`desktop/database/database.sqlite`), the PHP backend at `127.0.0.1:8100`, Vite dev server at `localhost:5173`, and all application source code.
  3. We can trigger NativePHP events and API endpoints directly.

### B. Safe External Link Dispatcher (`openExternalUrl`)
1. Create a dedicated client helper: `desktop/resources/js/lib/open-external.ts`:
   - Uses `sendJson('/open', 'POST', { url })` (which sends `Accept: application/json` and `X-XSRF-TOKEN`).
   - Ensures `OpenExternalController` receives `expectsJson() === true` and responds with `{ ok: true }` without triggering Inertia visits.
   - Creates a reusable `<ExternalLink href="..." ...>` component with standard Lucide `ExternalLink` icon.
2. In `servers/create.tsx`, `servers/show.tsx`, `workspaces/index.tsx`, `runs/show.tsx`, `devboxes/project.tsx`:
   - Replace `<Link href={open().url} method="post">` and `router.post('/open')` with `openExternalUrl(url)` or `<ExternalLink>`.
3. In `servers/create.tsx`:
   - Add state remembering to `useForm('servers.create', { ... })` so form state survives any accidental browser navigation or history back/forward.

### C. Backend Caching & Performance Optimization
1. **`StackCatalog`**:
   - Add memoization (`private ?array $memoized = null`) within the request lifecycle.
   - Cache results in `Cache::remember('stack_catalog.everything', now()->addSeconds(60), ...)` so repeated controller queries take 0ms.
   - Add `forget()` method to bust cache on server creation, deletion, or refresh.
2. **`LocalCluster`**:
   - Cache `detect()` in `Cache::remember('local_cluster.detect', now()->addSeconds(30), ...)`.
3. **`ClusterStatus`**:
   - Cache unconfigured / null results for 60 seconds so failing / unconfigured clusters don't repeatedly spawn 180s CLI commands on every deferred request.
4. **`DashboardController`**:
   - Make `missingToolsCount` and `toolsReady` computed from cached readiness status or defer them so initial dashboard HTML renders in < 50ms.
5. **Direct Sidebar Route Resolution**:
   - In `app-layout.tsx`, resolve active server context so Tools, Mail, and Plex links point directly to `/servers/{current}/tools` instead of hopping through `/tools` 302 redirects.

### D. Inertia v3 Navigation & Instant Visits
1. **Sidebar Link Prefetching & Stale-While-Revalidate**:
   - In `app-layout.tsx`, add `prefetch` and `cacheFor={['30s', '1m']}` to sidebar navigation items:
     ```tsx
     <Link
       href={item.href}
       prefetch
       cacheFor={['30s', '1m']}
       ...
     >
     ```
   - On hover (>75ms), Inertia prefetches the page.
   - On click, Inertia serves cached data instantly (0ms transition!), revalidating in background if stale.
2. **Progress Indicator**:
   - In `desktop/resources/js/app.tsx`, tune `progress`:
     ```ts
     progress: {
       delay: 100,
       color: '#4f46e5',
       showSpinner: false,
     }
     ```
3. **Global Shared Data (`HandleInertiaRequests`)**:
   - Use `shareOnce` for static/rarely-changing global props (`theme`, `hideProjects`, `experimental`, `demoMode`, `untested`).

---

## 3. Implementation Steps

1. **Step 1**: Implement `open-external.tsx` & `<ExternalLinkButton />` component in `desktop/resources/js/lib/open-external.tsx`. [Completed]
2. **Step 2**: Refactor all `/open` calls across all pages to use `openExternalUrl`. [Completed]
3. **Step 3**: Add `useForm('servers.create', ...)` form state remembering to `servers/create.tsx`. [Completed]
4. **Step 4**: Implement caching and memoization in `StackCatalog.php` and `LocalCluster.php`. [Completed]
5. **Step 5**: Fix negative/timeout sentinel caching in `ClusterStatus.php`. [Completed]
6. **Step 6**: Add Inertia v3 `prefetch` and `cacheFor` to `app-layout.tsx` navigation items, and tune progress indicator in `app.tsx`. [Completed]
7. **Step 7**: Update `HandleInertiaRequests.php` to leverage `shareOnce`. [Completed]
8. **Step 8**: Formalize ADR 0011 and ADR 0012 in `desktop/docs/decisions/` and index in `README.md`. [Completed]
9. **Step 9**: Defer dashboard sections (`stats`, `localCluster`, `toolsReady`, `projects`, `servers`) in `DashboardController.php` with Inertia v3 `<Deferred>` and shape-matching pulsing skeletons in `dashboard/index.tsx`. [Completed]
10. **Step 10**: Verify Pest test suite (487/487 passed), Pint, PHPStan, Biome/vp check (0 errors), and production Vite build. [Completed]
11. **Step 11**: Fix SSR runtime compatibility bugs (`window.localStorage` access in `welcome-onboarding.tsx`, `getServerSnapshot` in `right-drawer-open.ts`, and truthy `<Deferred>` fallbacks). [Completed]
12. **Step 12**: Brand and enrich NativePHP / Electron application metadata (`app.name = 'LaraKube'`, `app.setAboutPanelOptions`, custom LaraKube icon in `desktop/nativephp/electron/build/icon.png`, and `Menu::default()` in `NativeAppServiceProvider.php`). [Completed]
13. **Step 13**: Exclude local dev clusters (`orbstack`, `docker`, `k3d`, `minikube`, `kind`) from backup checks via `Server::isStackLocal`, and promote backups into a balanced 4-card Fleet Metrics grid (`Backup Protection`), eliminating the disruptive full-width yellow warning banner. [Completed]
