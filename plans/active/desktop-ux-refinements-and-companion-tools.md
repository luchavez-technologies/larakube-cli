# Plan: LaraKube Desktop UX Polish, Brand Logos, Multi-Instance Tools, and Layout Alignment

## 1. Overview
This plan implements the latest suite of user experience refinements, brand identity alignments, and architectural features for **LaraKube Desktop**:
1. **Brand Identity & App Logo**:
   - Replace old app logo assets with `desktop/logo-v2.png` across the entire desktop app (`desktop/public/logo.png`, `desktop/public/icon.png`, `desktop/public/apple-touch-icon.png`).
   - Update `app-layout.tsx` to display the new 4-tile brand logo emblem with modern styling.
2. **Accurate Vector Logos for All Tools & Companions**:
   - Install and integrate `@icons-pack/react-simple-icons` for real vector SVGs and authentic brand colors (PocketBase, Matrix, OpenBao, Twenty, LiveKit, Uptime Kuma, Bitwarden/Vaultwarden, Forgejo, Grafana, Prometheus, MinIO, n8n, Metabase, Redis, Postgres, MongoDB, MySQL, MariaDB, Adminer, phpMyAdmin, WireGuard, ownCloud, Plausible, Umami, Traefik).
   - Supply high-fidelity custom SVGs for remaining ecosystem tools (Zitadel, NetBird, Teable, Documenso, GlitchTip, Stalwart/Mailpit, Kutt, Headlamp).
   - Fix `servers/show.tsx` to stop rendering raw emoji `{tool.icon}` and use `<ToolLogo tool={tool} size="md" />`.
3. **Multi-Instance Tool Architecture & UX (e.g. PocketBase, MinIO, n8n)**:
   - **Data Model**: Leverage LaraKube's existing multi-instance registry model (`brand [instance]`, `tool.instance`, `host`, `url`).
   - **Tools Catalog (`tools/index.tsx`)**:
     - Render distinct instance badges (`tool.instance`) next to tool name.
     - For multi-instance tools (e.g., PocketBase, MinIO, Flow), provide an "+ Add Instance" action that opens the install modal with an instance subdomain/name prompt, allowing operators to deploy multiple independent instances into the cluster.
     - Ensure each instance has its own status, domain, and detail link.
   - **Server Detail (`servers/show.tsx`)**:
     - Render each instance distinctly with its instance badge, URL, and direct actions.
   - **Tool Detail (`tools/show.tsx`)**:
     - Display active instance name and provide sibling instance switcher links if multiple instances are installed.
4. **Server Page Layout Realignment**:
   - Reorder cards according to operator priority:
     - **Left Column**:
       1. `Domain & SSL` (very top — primary ingress prerequisite)
       2. `Hosted Projects`
       3. `Cluster Tools` (with `<ToolLogo />` and instance badges)
       4. `Plex Commons` (very bottom — cluster-wide background plumbing)
     - **Right Column**:
       1. `Connection` (kubectl context, SSH, larakube login)
       2. `Team Access & RBAC` (teammate scoped kubeconfigs & roles)
       3. `Danger Zone` (delete server)
5. **Left Sidebar Navigation Order**:
   - Re-order sidebar navigation:
     `Dashboard` -> **`Servers`** -> **`Projects`** -> **`Tools`** -> `Activity` -> `Setup` -> `Settings`.
6. **"Hide Projects" Toggle (Cluster Tools Mode)**:
   - Add a preference to hide `Projects` from navigation for users solely managing servers and cluster companion tools.
   - Persist in `GlobalSettings.php` (and `~/.config/larakube/settings.json`) and share via Inertia middleware, with local storage fallback.
7. **Onboarding Animation for New Installations**:
   - Create an animated onboarding component (`WelcomeOnboarding`) on the Dashboard when no servers or projects exist yet.
   - Animated glowing cubes inspired by `logo-v2.png` with guided 1-2-3 setup steps.

---

## 2. Implementation Steps

1. **Brand Assets & Layout Setup**:
   - Ensure `desktop/public/logo.png`, `desktop/public/icon.png`, `desktop/public/apple-touch-icon.png` match `desktop/logo-v2.png`.
   - Update `app-layout.tsx` logo display and swap `Servers` and `Projects` in navigation array.
   - Add `hideProjects` toggle support in `app-layout.tsx`.

2. **Vector Brand Logos (`tool-logo.tsx`)**:
   - Import icons from `@icons-pack/react-simple-icons` (`SiPocketbase`, `SiMatrix`, `SiOpenbao`, `SiTwenty`, `SiLivekit`, `SiUptimekuma`, `SiBitwarden`, `SiForgejo`, `SiGitea`, `SiGrafana`, `SiPrometheus`, `SiMinio`, `SiN8n`, `SiMetabase`, `SiRedis`, `SiMongodb`, `SiPostgresql`, `SiMysql`, `SiMariadb`, `SiAdminer`, `SiPhpmyadmin`, `SiWireguard`, `SiOwncloud`, `SiUmami`, `SiPlausibleanalytics`, `SiTraefikproxy`, `SiSentry`, `SiOutline`).
   - Add specialized vector SVGs for NetBird, Zitadel, Teable, Documenso, GlitchTip, Stalwart/Mailpit, Headlamp.
   - Replace emoji rendering in `servers/show.tsx` with `<ToolLogo tool={tool} />`.

3. **Multi-Instance Support**:
   - Add instance badge rendering in `servers/show.tsx`, `tools/index.tsx`, and `tools/show.tsx`.
   - Support adding new instances for multi-instance capable tools.
   - Update `ClusterToolController.php` to handle `--instance` in `store` request if provided.

4. **Server Page Restructure (`servers/show.tsx`)**:
   - Reorder layout to: Left = `Domain & SSL` -> `Hosted Projects` -> `Cluster Tools` -> `Plex Commons`.
   - Right = `Connection` -> `Team Access & RBAC` -> `Danger Zone`.

5. **Hide Projects Setting**:
   - Update `GlobalSettings.php` and `SettingsController.php` to support `hideProjects` boolean.
   - Update `HandleInertiaRequests.php` to share `hideProjects`.
   - Add toggle in `settings/index.tsx`.

6. **Onboarding Animation (`WelcomeOnboarding`)**:
   - Create `desktop/resources/js/components/welcome-onboarding.tsx` with CSS floating animations, brand color accents, and guided quick-start cards.
   - Render conditionally in `dashboard/index.tsx`.

7. **Tools Catalog UI Overhaul (`tools/index.tsx`)**:
   - Replaced cramped 6-column auto-fill grid (`minmax(250px,1fr)`) with an elegant, spacious 3-column grid (`grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4.5`), eliminating awkward text truncation.
   - Calibrated icon contrast and styling in `ToolLogo`: added dedicated brand-tinted containers and dark/light aware fills for LiveKit, PocketBase, Matrix, Twenty, Outline, and companions to guarantee high contrast.
   - Fixed missing tool title bug when `tool.brand` only contains an instance tag by adding robust fallbacks in `toolName`.
   - Redesigned `InstalledCard` into a rich service card with dedicated ingress URL address box, copyable domain, direct "Visit" external link, instance badges, and "+ Add Instance" action.
   - Redesigned `AvailableCard` into a clean App Store integration card with clear 2-line descriptions, tech tags, and refined secondary-tinted `Install` buttons (`bg-tools/10 text-tools hover:bg-tools hover:text-white`), removing visual fatigue caused by repeating solid purple blocks.
   - Unified search and filter tabs into a coherent control bar on the left, cleanly aligning the verification timestamp on the right.

8. **Verification & Quality Gate**:
   - Proactively run `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `npm run types:check`, and `php artisan test`.
