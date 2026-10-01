# Plan: Desktop UX Refinements - Activity UI, View Consistency & Dynamic Cluster Detection

## 1. Context & Motivation
Following the rollout of the Fleet Dashboard, In-Place Terminal, Multi-Environment Support, Settings, and Plex Commons, key developer experience refinements have been requested:

1. **Local Cluster Detection vs Static Placeholder**:
   - The dashboard previously displayed a static placeholder `k3d`.
   - Operators on macOS use OrbStack (which runs native Kubernetes with context `orbstack`, not k3d), Docker Desktop, or Colima; operators on Linux/WSL use native `k3s` installed via `cluster:setup`.
   - The cluster card dynamically detects the true local cluster engine and status via `kubectl`.

2. **Activity UI (Runs) Discoverability**:
   - Activity feed rows now show target type, target name, clickable project/server links, environment tags, live text search, and segmented filter pills.

3. **Projects, Servers, and Tools Visual Consistency (Cards ⟷ Table)**:
   - Projects, Servers, and Cluster Tools all support seamless toggling between **Cards (Grid)** and **Table (List)** mode, with state persisted in `localStorage`.

4. **TLD Resolution Precision (.test vs .kube)**:
   - Operators' global TLD configuration in `~/.larakube/config.json` (e.g. `test` or `kube`) was previously masked on the project page by a hardcoded `'kube'` fallback in the UI.
   - `ProjectInspector` and `ProjectController` will pass the configured global TLD so domain previews (`.test` / `.dev.test`) and the TLD selector reflect the operator's actual configuration.

5. **Button Semantic Palette Correction (Primary vs Danger)**:
   - `buttonClass('primary')` was mapped to `bg-accent` (#d1261b crimson red), causing create/new/save actions to render in alert red.
   - Re-align `primary` to modern dark neutral (`bg-ink text-white hover:bg-ink/90 active:bg-ink/95 shadow-xs`) or brand blue, reserving red strictly for destructive actions (`danger` and `dangerFill`).

6. **Icon Library Architecture Plan**:
   - Plan the migration from ad-hoc emojis/SVGs to an industry-standard vector icon library (`lucide-react`).

---

## 2. Architecture & Design

### A. Dynamic TLD Resolution
- In `ProjectInspector.php`:
  - Read `GlobalSettings::getLocalTld()` (reads `~/.larakube/config.json`).
  - Provide `globalTld` and compute `effectiveTld = localTld ?? globalTld`.
- In `projects/show.tsx`:
  - Replace hardcoded fallback `${project.localTld ?? 'kube'}` with `${project.localTld || globalTld || 'test'}`.
  - In the TLD dropdown, render `<option value="">Default (.${globalTld || 'test'})</option>`.

### B. Button Semantic Palette Correction
- In `desktop/resources/js/components/button.tsx`:
  - `primary`: `bg-ink text-white hover:bg-ink/90 active:bg-ink/95 shadow-xs`
  - `secondary`: `bg-surface text-ink ring-1 ring-line ring-inset hover:bg-paper`
  - `danger`: `bg-surface text-accent ring-1 ring-accent-line ring-inset hover:bg-accent-tint`
  - `dangerFill`: `bg-accent text-white hover:bg-accent-hover`
- "Create", "New Project", "Deploy", "Save" now render in sleek, authoritative dark ink, while "Destroy Server", "Delete", and "Cancel" use red `danger`.

### C. Tools Page Table / Card Toggle
- In `desktop/resources/js/pages/tools/index.tsx`:
  - Integrate `ViewToggle` (`cards` | `table`).
  - Persist preference in `localStorage.getItem('larakube_view_mode_tools')`.
  - **Cards mode**: existing `Section` with `InstalledCard` and `AvailableCard`.
  - **Table mode**: clean unified table with columns:
    - Tool (icon + name)
    - Service (underlying package)
    - Address / Description (host with direct link or purpose)
    - Status (StatusPill)
    - Action (`Open ↗`, `Details`, `Install`)

### D. Control Height & Legibility Standardization
- **The Problem**: Controls had erratic heights across screens. ViewToggle was ~28px (`py-1 text-xs`), standard buttons were ~38px (`py-2 text-sm`), Dashboard header used `'sm'` (~30px) while Projects used `'md'`, and input/select fields had varying paddings without fixed heights.
- **The Solution**: Standardize on a strict 3-tier height system:
  1. **Standard (`md`)**: `h-9` (36px) — Used for all page header actions, primary/secondary buttons, search bars, inputs, selects, and segmented toggles. Font: `text-[13px] font-medium`.
  2. **Compact (`sm`)**: `h-8` (32px) — Used for table row action buttons, card action buttons, and dense inline pills. Font: `text-xs font-medium`.
  3. **Large (`lg`)**: `h-10` (40px) — Used for modal primary submission buttons and full-width wizard CTAs. Font: `text-sm font-medium`.
- **ViewToggle Alignment**: Set container to `h-9 px-1` with inner toggle buttons to `h-7 px-3 text-xs font-medium`, perfectly matching adjacent `h-9` buttons in toolbars.

### E. Vector Icon Library Integration (`lucide-react`)
- **Package**: `lucide-react` installed.
- **Icon Actions Mapping**:
  - `New project` / `Create server` / `New environment`: `<Plus className="size-4" />`
  - `Add existing folder`: `<FolderPlus className="size-4" />`
  - `Delete` / `Destroy server` / `Remove environment`: `<Trash2 className="size-4" />`
  - `Refresh`: `<RotateCw className="size-3.5" />`
  - `Up` / `Start`: `<Play className="size-3.5 fill-current" />`
  - `Down` / `Stop`: `<Square className="size-3.5 fill-current" />`
  - `Open ↗`: `<ExternalLink className="size-3.5" />`
  - `ViewToggle`: `<LayoutGrid className="size-3.5" />` and `<List className="size-3.5" />`

### F. Server Screen: Hosted Projects & Cluster Tools Visibility
- **The Problem**: `servers/show` only showed "Next Steps" with domain/SSL and static "Coming soon" for Deploy, with zero visibility into which projects and tools are actually running on the server.
- **The Solution**:
  1. In `ServerController::show`:
     - Inspect all registered projects to find ones linked to `$server['ip']`.
     - Fetch installed cluster tools from `ToolCatalog`.
  2. In `servers/show.tsx`:
     - **Hosted Projects Card**: list all projects deployed/linked to this server, with framework badge, domain/URL link, status pill, and "Manage" button.
     - **Installed Cluster Tools Card**: list active cluster tools with icon, name, service, address, and "Open" / "Details" buttons, plus a "Browse all tools" CTA.
     - **Domain & SSL**: streamline into dedicated configuration card.

---

## 3. Implementation Steps & TDD
1. Install `lucide-react` in `desktop/`.
2. Update `button.tsx` with explicit heights:
   - `sm`: `h-8 px-3 text-xs gap-1.5`
   - `md`: `h-9 px-3.5 text-[13px] gap-2`
   - `lg`: `h-10 px-4 text-sm gap-2`
3. Update `view-toggle.tsx`:
   - Use Lucide `LayoutGrid` and `List` icons.
   - Standardize outer container to `h-9` with `h-7` toggle buttons so it seamlessly matches `h-9` header buttons.
4. Update `DashboardIndex`:
   - Switch header action buttons from `'sm'` to default `'md'` (`h-9`), adding `<Plus className="size-4" />`.
5. Update `ProjectsIndex`:
   - Add `<Plus className="size-4" />` to "New project" and `<FolderPlus className="size-4" />` to "Add existing folder".
6. Update `ServersIndex`:
   - Add `<Plus className="size-4" />` to "Create server".
7. Update `ServerShow` & `ProjectShow`:
   - Add `<Trash2 className="size-4" />` to destroy/delete buttons.
   - Add `<Play className="size-3.5" />` and `<Square className="size-3.5" />` to Up/Start and Down/Stop buttons.
8. Update `ToolsIndex`:
   - Standardize search input and server select to `h-9`.
   - Add `<RotateCw className="size-3.5" />` to Refresh button.
   - Add `<ExternalLink className="size-3.5" />` to Open buttons.
9. Implement Server-Hosted Projects & Cluster Tools Visibility (Section F):
   - In `ProjectInspector`: extracted `serverContext` and resolved VPS IP from `context` (`larakube-<ip>`) if `ip` was omitted.
   - In `ServerController::show()`: injected `ProjectInspector` and `ToolCatalog`. Filtered registered projects hosted on the server's IP or context across all environments. Defer-loaded cluster tools for the server's context.
   - In `Card`: added optional `action` prop to header for clean button/link integration.
   - In `servers/show.tsx`: replaced the static "Next steps" card and "Coming soon" button with:
     - **Hosted Projects Card**: Displays apps bound to this server with framework badge, environment tags, host, and Manage button with `ArrowRight`. Empty state offers quick actions to link or create a project.
     - **Installed Cluster Tools Card**: Displays active cluster tools installed on this server with icon, name, engine tag, host/URL, `Open ↗` and `Details` buttons, with a "Browse catalog" header link.
     - **Domain & SSL Card**: Houses ExternalDNS and Automatic SSL (Cloudflare DNS challenge) configuration cleanly.
10. Added feature test in `ServersTest.php` asserting `projects` and bound workloads appear on the server show page.
11. Quality Verification:
    - Pint: Passed cleanly (`./vendor/bin/pint --test`).
    - PHPStan: 0 errors (`./vendor/bin/phpstan analyse --memory-limit=1G`).
    - Pest: 90 passed, 652 assertions.
    - Vite/TypeScript: `npm run build` compiled cleanly in 2.93s.
12. Activity UI Overhaul & Target Name Resolution:
    - Replaced raw numeric target IDs (`3`, `2`) with proper app names extracted from labels (`hello-world-desktop`, `hello-laravel`) in `RunController::resolveTarget`.
    - Added top KPI metrics bar (Total, Succeeded, Failed, Active) with quick-filter interaction.
    - Grouped runs chronologically by day (`Today`, `Yesterday`, etc.) with section counts.
    - Replaced ragged flex layout with an aligned, high-density table structure: Status, Activity (label + ID/kind), Target (vector icon micro-pill), Environment, Duration, Dual Timestamp (relative + exact), and Chevron.
    - Made entire row clickable with smooth hover state and standalone link for target entity.
