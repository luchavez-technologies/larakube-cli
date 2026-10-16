# Plan: Unified Environment Settings Drawer (Sliding Sidebar)

## Goal Description
Replace the fragmented, segregated inline edit forms inside the 3-column cloud environment overview cards (`Target Server`, `Public Address`, `Delivery Pipeline`) on the project show page with a unified, sliding right-anchored drawer (`EnvironmentSettingsDrawer`). 

When an operator clicks "Edit" / "Change" on any of the 3 cards, the drawer slides in from the right edge, smoothly scrolls to the requested section, and highlights it with a temporary visual focus pulse animation.

---

## User Review Required

> [!IMPORTANT]
> **Consolidation of Inline Edit Forms**:
> - The inline expandable boxes (`showSwitchServer`, `showEditHost`, and `showRotateConfirm`) inside the 3-column grid cards will be removed from the main page layout.
> - The 3 cards on the project page will remain clean, compact, and status-oriented at all times.
> - Clicking "Edit" or "Change" on any of the 3 cards will open the unified `EnvironmentSettingsDrawer`.

> [!NOTE]
> **Animation & Section Targeting**:
> When opened via a specific card's "Edit" button, the drawer will:
> 1. Auto-scroll to that section (`scrollIntoView({ behavior: 'smooth', block: 'start' })`).
> 2. Flash a subtle highlight ring/background pulse (`ring-2 ring-brand/60 bg-brand/5`) that smoothly fades out after 1.8 seconds.
> A top quick-nav pill bar inside the drawer will also allow jumping between `Target Server`, `Public Address`, and `Delivery Pipeline` instantly.

---

## Open Questions

None. The user's specification is clear and directly addresses the layout issues seen in the attached screenshots.

---

## Proposed Changes

### Desktop UI (`desktop/`)

#### [NEW] `resources/js/components/environment-settings-drawer.tsx`
Create a dedicated, reusable slide-over drawer component matching Desktop's slide-over standard (`useRightDrawerOpen`, fixed backdrop blur, `max-w-xl md:max-w-2xl` width, scrollable body):
- **Header**:
  - Environment title (e.g. `Environment Settings · PRODUCTION`)
  - Subtitle with project context
  - Quick-navigation pills (`Target Server`, `Public Address`, `Delivery Pipeline`) to jump/scroll to any section
  - Close button (`X` icon & Escape listener)
- **Section 1: Target Server (`#section-server`)**:
  - Current linked server details
  - Rebind server selector (`SelectMenu` with ready servers)
  - Rebind / Link action button with `<RotateCw className="size-3" />`
- **Section 2: Public Address (`#section-address`)**:
  - Integrated `HostPicker` component
  - Subdomain & Cluster Zone selector with ExternalDNS / TLS badges
  - Live URL preview
  - ExternalDNS Auto-Sync banner / manual registrar DNS instructions
  - Save action button with `<Save className="size-3" />`
- **Section 3: Delivery Pipeline & CI/CD (`#section-pipeline`)**:
  - Container registry selector (`ghcr`, `dockerhub`, `gitlab`, `forgejo`, `gar`)
  - Target branch & image slug inputs
  - Security gate toggles (Tests, Strict, Gitleaks, Semgrep, Trivy)
  - Scoped secret rotation trigger card (`Rotate deployer secrets`)
  - Save pipeline button with `<Save className="size-3" />`
- **Visual Focus Cue**:
  - Evaluates `focusedSection` prop on open.
  - Automatically smooth-scrolls to the section element.
  - Applies a glowing highlight ring that transitions away after 1.8 seconds.

#### [MODIFY] `resources/js/pages/projects/show.tsx`
- In `CloudEnvironmentOverviewCard`:
  - Replace individual inline state toggles (`showSwitchServer`, `showEditHost`, `showRotateConfirm`) with a single drawer state:
    `const [drawerSection, setDrawerSection] = useState<'server' | 'address' | 'pipeline' | null>(null);`
  - Update action buttons on all 3 cards to trigger `setDrawerSection('server')`, `setDrawerSection('address')`, or `setDrawerSection('pipeline')`.
  - Mount `<EnvironmentSettingsDrawer>` bound to `isOpen={drawerSection !== null}` and `initialSection={drawerSection ?? 'server'}`.
  - Remove crowded inline form containers from inside the 3-column grid cards, keeping the cards neatly aligned and visually balanced.

---

## Verification Plan

### Automated Tests
1. **Linter & Formatter**:
   ```bash
   npm run check
   npm run check:fix
   ```
2. **TypeScript Compilation**:
   ```bash
   npm run types:check
   ```
3. **Pest Tests**:
   ```bash
   ./vendor/bin/pest tests/Feature/ProjectsTest.php
   ```
4. **PHP Code Style**:
   ```bash
   ./vendor/bin/pint --test
   ```

### Manual Verification
1. Navigate to the project show page (`/projects/{id}`).
2. Verify all 3 cards (`Target Server`, `Public Address`, `Delivery Pipeline`) render at a clean, uniform height without inline expansion boxes.
3. Click "Change" on `Target Server`: Verify the drawer slides in from the right and smoothly highlights the Target Server section.
4. Click "Edit" on `Public Address`: Verify the drawer opens and smoothly highlights the Public Address / HostPicker section.
5. Click "Edit" on `Delivery Pipeline`: Verify the drawer opens and highlights the CI/CD Pipeline section.
6. Test updating a host or linking a server from within the drawer.
