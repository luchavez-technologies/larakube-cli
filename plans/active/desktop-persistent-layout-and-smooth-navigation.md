# Desktop Persistent Layout & Smooth Navigation Plan

## Overview
LaraKube Desktop uses Inertia.js with React. Currently, every page wraps `<AppLayout>` within its own render output. When navigating between pages or clicking back buttons/breadcrumbs (e.g. `← Projects`, `← Servers`), React unmounts the current page component and destroys `<AppLayout>`. It then mounts a fresh `<AppLayout>` for the next page. This causes:
1. The sidebar, header, theme observer, notification listener, and shortcut monitors to destroy and recreate.
2. A visible screen flash and repaint that feels like a hard browser reload.
3. Loss of scroll position when returning to lists.

This plan converts the entire LaraKube Desktop frontend to **Inertia Persistent Layouts**, adds **scroll restoration (`preserveScroll`)** to all back links, and eliminates navigation latency.

---

## Architecture & Implementation Steps

### Step 1: App-Wide Default Persistent Layout in `desktop/resources/js/app.tsx`
- Configure `createInertiaApp`'s `resolve` callback in `desktop/resources/js/app.tsx`.
- Dynamically resolve pages using `import.meta.glob('./pages/**/*.tsx')`.
- For any page where `page.default.layout === undefined`, assign a default persistent layout:
  ```tsx
  page.default.layout = (pageNode: React.ReactNode) => (
      <AppLayout>{pageNode}</AppLayout>
  );
  ```
- Allow pages to opt out by specifying `Page.layout = null` or a custom layout.
  - Opt-out 1: `desktop/resources/js/pages/tray/index.tsx` (macOS menubar popover).
  - Opt-out 2: `desktop/resources/js/pages/onboarding/index.tsx` (fullscreen onboarding flow).

### Step 2: Modernize `AppLayout`
- Update `AppLayout` in `desktop/resources/js/layouts/app-layout.tsx` so `title` is optional (`title?: string`).
- If `title` is provided, render `<Head title={title} />`.
- When child pages render `<Head title="..." />`, Inertia automatically updates the window title without requiring `AppLayout` to re-mount.

### Step 3: Clean up Page Components
- In pages that previously wrapped `<AppLayout>`, remove the outer `<AppLayout>` wrapper and use `<Head title="..." />` where appropriate:
  - `projects/index.tsx`, `projects/show.tsx`, `projects/create.tsx`
  - `servers/index.tsx`, `servers/show.tsx`, `servers/create.tsx`
  - `tools/index.tsx`, `tools/show.tsx`
  - `devboxes/index.tsx`, `devboxes/show.tsx`, `devboxes/project.tsx`
  - `dashboard/index.tsx`, `settings/index.tsx`, `runs/index.tsx`, `runs/show.tsx`
  - `mail/index.tsx`, `drive/index.tsx`, `openbao/index.tsx`, `sso/index.tsx`, `plex/index.tsx`, `readiness.tsx`, `help/index.tsx`, `simulator/index.tsx`

### Step 4: Add `preserveScroll` to All Back Buttons and Breadcrumbs
- Update back links with `preserveScroll`:
  - `projects/show.tsx`: `<Link href={index().url} preserveScroll>← Projects</Link>`
  - `projects/create.tsx`: `<Link href={index().url} preserveScroll>← Projects</Link>`
  - `servers/show.tsx`: `<Link href={index().url} preserveScroll>← Servers</Link>`
  - `servers/create.tsx`: `<Link href={index().url} preserveScroll>← Servers</Link>`
  - `tools/show.tsx`: `<Link href={showServer(server.id).url} preserveScroll>← Apps on {server.name}</Link>`
  - `devboxes/show.tsx`: `<Link href={index().url} preserveScroll>← Dev Boxes</Link>`
  - `devboxes/project.tsx`: `<Link href={index().url} preserveScroll>← Projects</Link>`
  - `runs/show.tsx`: `<Link href={backLink(run).url} preserveScroll>← {backLink(run).label}</Link>`

### Step 5: Verification & Zero-Regression
- Execute `vp check` (`npm run check`) in `desktop/`.
- Execute `tsc --noEmit` (`npm run types:check`) in `desktop/`.
- Run full Pest test suite (`php -d memory_limit=2G vendor/bin/pest --parallel --processes=4`).
