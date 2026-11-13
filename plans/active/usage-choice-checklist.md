# Redesign UsageChoice Mode Cards with Capability Checklists

## Goal Description
Replace the current prose + bottom tool/framework icon strip on the two Setup/Onboarding mode cards (`UsageChoice`) with a scannable 1-line tagline, a green `✓` capability checklist explaining what each mode lets you do, and a compact footer note for local requirements and download size.

Also rename the left (`tools`) card from **"Install tools on a server"** to **"Servers & Cluster Tools"** so it pairs naturally with **"Local development"**.

## Proposed Changes

### Desktop Frontend (`desktop/resources/js/`)

#### [MODIFY] `desktop/resources/js/components/setup/usage-choice.tsx`
- Rename the `tools` option title to **"Servers & Cluster Tools"** and `apps` option title to **"Local development"**.
- Replace `installs` + `<ProviderLogo />` / `<FrameworkLogo />` strip with a `highlights: string[]` capability checklist rendered with `Check` icons (`lucide-react`):
  - **Servers & Cluster Tools (`tools`)**:
    - Tagline: *"Provision cloud servers and deploy self-hosted apps for your team."*
    - Checklist:
      - `Create & manage cloud servers (VPS, EKS, GKE)`
      - `Deploy 45+ Cluster Tools (chat, wiki, auth, storage, n8n)`
      - `Manage domains, TLS, backups & Plex Commons`
    - Footer note: `Nothing runs locally · ~150MB download`
  - **Local development (`apps`)**:
    - Tagline: *"Everything in Servers & Cluster Tools, plus:"*
    - Checklist:
      - `Build & preview Laravel, Next.js, Vite, Django & more locally`
      - `Run a local Kubernetes cluster on this computer`
      - `Unlocks Projects & Dev Boxes in the sidebar`
    - Footer note: `Requires a container runtime (and WSL on Windows) · ~300MB+`
- Remove unused `FrameworkLogo` and `ProviderLogo` imports.

#### [MODIFY] `desktop/resources/js/pages/onboarding/index.tsx`
- Update the summary pill on the final step from `'Install tools on a server'` to `'Servers & Cluster Tools'` so it matches `UsageChoice`.

## Verification Plan
- `cd desktop && npx vp check && npx tsc --noEmit && composer test`
