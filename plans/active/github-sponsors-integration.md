# Plan: GitHub Sponsors Integration in LaraKube Desktop

**Date:** 2026-10-14  
**Author:** Antigravity  
**Status:** In Progress  

---

## 1. Overview & Objective

The user requested adding official GitHub Sponsors integration elements to support the development of LaraKube Desktop:
- **Sponsor Button**: `<iframe src="https://github.com/sponsors/luchavez-technologies/button" title="Sponsor luchavez-technologies" height="32" width="114" style="border: 0; border-radius: 6px;"></iframe>`
- **Sponsor Card**: `<iframe src="https://github.com/sponsors/luchavez-technologies/card" title="Sponsor luchavez-technologies" height="225" width="600" style="border: 0;"></iframe>`

This plan establishes the strategic placement, UX hierarchy, Electron runtime safety, and implementation details for these sponsorship elements.

---

## 2. Strategic Placement Hierarchy

To maintain a clean, professional developer experience without cluttering daily workflows:

1. **Help & Community Page (`/help`) [Primary Showcase]**:
   - **`SponsorCard`**: Placed directly below `HelpVideosCard` in the main content grid. Provides full visibility for the interactive 600px GitHub Sponsors card with organisation avatar, bio, and tier button.
   - **`HelpCommunityCard`**: Enhanced with a dedicated "Sponsor us" section featuring the compact `SponsorButton` (114×32px) and a direct `HelpLinkRow` styled with a pink `Heart` icon.

2. **Settings Page (`/settings`) [Secondary Anchor]**:
   - Right-hand column below `KubeContextsCard` and `AI Agent Bridge (MCP)`.
   - Embeds a sleek "Support LaraKube" card explaining that LaraKube is open source and maintained by LuchTech, featuring the official `SponsorButton`.

3. **App Sidebar Footer (`AppLayout`) [Global Discreet Access]**:
   - Bottom of the navigation sidebar (`aside`).
   - A minimalist, styled button with Lucide `Heart` icon linking to `https://github.com/sponsors/luchavez-technologies`.

---

## 3. Electron Runtime & UX Safety Standards

1. **ADR 0011 Invariant**: External URLs must be opened via OS browser (`openExternal`), never triggering Inertia visits or navigating within the Electron window.
2. **Iframe Top Navigation Trapping Defense**:
   - GitHub's embedded iframe buttons use `target="_top"`. In Electron, clicking this would attempt to navigate the primary Electron app window to `https://github.com/sponsors/luchavez-technologies`.
   - In `nativephp/electron/src/main/index.js`, register `will-navigate` and `setWindowOpenHandler` listeners on `webContents`.
   - Any navigation targeting a non-localhost URL (`https://*` or `http://*` not matching `127.0.0.1` or `localhost`) is prevented and dispatched to `shell.openExternal(url)`.

---

## 4. Implementation Steps

1. **Electron Main Hook**:
   - Update `nativephp/electron/src/main/index.js` to import `shell` and intercept window navigations to external URLs.
2. **Component Creation**:
   - Create `desktop/resources/js/components/sponsor-button.tsx` (reusable iframe button with loading state).
   - Create `desktop/resources/js/components/sponsor-card.tsx` (card wrapping the 600×225px iframe with fallback action button).
3. **View Integrations**:
   - Update `desktop/resources/js/components/help-community-card.tsx` to include "Sponsor us".
   - Update `desktop/resources/js/pages/help/index.tsx` to include `SponsorCard`.
   - Update `desktop/resources/js/pages/settings/index.tsx` to include "Support LaraKube" card.
   - Update `desktop/resources/js/layouts/app-layout.tsx` to include sidebar sponsor link.
4. **Verification & Testing**:
   - Run Biome (`npm run check:fix`).
   - Run PHPStan & Pint (`composer types:check && composer lint:check`).
   - Run Pest tests (`vendor/bin/pest`).
   - Commit via Conventional Commits.
