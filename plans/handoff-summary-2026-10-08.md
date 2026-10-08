# LaraKube Engineering Handoff & Session Summary (2026-10-08)

> **For Claude / Successor AI Agents**: This document captures the complete architectural context, recent work completed, active repository state, and pending next steps across both `desktop/` and `cli/`.

---

## 📌 Executive Summary

Over this session, we overhauled the **1-Click Quick Launch Companion App flow** in the LaraKube Desktop app, integrated cluster **ExternalDNS** zone auto-detection, eliminated confusing local-only domain recommendations (`nip.io` and `.dev.test`) on cloud VPS clusters, resolved an execution bug with `n8n` deployment, and initiated the **Apple Developer Program enrollment & macOS notarization strategy**.

All code changes are fully tested, formatted with Pint/ESLint, type-checked with TypeScript/PHPStan, verified with Pest in parallel, and committed under Conventional Commits (ADR 0025).

---

## 🛠️ Work Completed in this Session

### 1. 1-Click Quick Launch Wizard Overhaul (`desktop/resources/js/components/quick-launch-modal.tsx`)
- **Conversion to 4-Step Stepper**: Transformed the previously monolithic Quick Launch modal into an intuitive, guided 4-step wizard:
  - **Step 1: Application Selection**: Choose between PocketBase, n8n, and WordPress with live feature badges and capability pills.
  - **Step 2: Target Cluster**: Choose among ready/running Kubernetes servers with provider logos (Local, GCP, Hetzner, DO, AWS).
  - **Step 3: Configuration**: Domain address, database engine (if supported), administrator credentials, and companion integrations (Stalwart Mail, Zitadel SSO).
  - **Step 4: Review & Launch**: Final architecture confirmation before dispatching the background run.
- **Top Dashboard Banner Integration**: Positioned the 1-Click quick launch triggers prominently on the dashboard top banner. Removed redundant `+ Server` and `+ Project` buttons from the banner.
- **Dashboard Layout & Health Score**: Removed Uptime Kuma from default presets. Fixed fleet health score gauge coloring logic so high health scores (93%+) display green (`ok`) rather than red.

### 2. ExternalDNS Auto-Sync & Domain Experience
- **ExternalDNS Priority**: When querying a target server's domains (`/servers/{server}/domains`), the modal detects active Cloudflare ExternalDNS zones and auto-selects them.
- **Visual Cloudflare Badge**: Features a green "ExternalDNS Auto-Sync (Zero Config)" callout explaining that DNS A-records and Traefik Let's Encrypt certificates are provisioned automatically.
- **Fallback DNS Guidance Card**: If ExternalDNS is absent on a cloud VPS (e.g. GoDaddy, Namecheap), it presents a copyable DNS A-record card (`Type: A, Name: <subdomain>, Value: <server_ip>, TTL: 600`).
- **Interactive Cluster Domains Dropdown**: Refactored the native select element into a custom dropdown showing TLS badges, Cloudflare tags, and an inline option to enter custom full domains.

### 3. Removal of `nip.io` & Strict Local vs Cloud DNS Separation
- **The Issue**: Previously, when no domain was connected, the modal recommended `${server.ip}.nip.io` or `.dev.test` even for remote cloud VPS clusters (like GCP/Hetzner).
- **The Fix**:
  - `.dev.test` is strictly reserved for local development clusters (K3d, Docker Desktop, OrbStack, KinD, Minikube).
  - `nip.io` recommendations have been completely stripped out (avoids Let's Encrypt rate-limiting failures and unpolished branding on real servers).
  - On cloud servers without connected domains, users are prompted to enter their real domain/subdomain with the manual DNS A-record helper.

### 4. `n8n` Quick Launch `--db` Flag Bug Resolution
- **The Bug**: Launching `n8n` via Quick Launch failed with `LARAKUBE n8n has no --db option.`
- **Root Cause**: In LaraKube, `n8n` uses embedded SQLite storage by default and does not accept a `--db` CLI flag. [`QuickActionController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/QuickActionController.php) was grouping `n8n` with `wordpress` and injecting `--db=sqlite`.
- **The Fix**:
  - Restricted `--db` flag forwarding exclusively to `wordpress` in [`QuickActionController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/QuickActionController.php).
  - Removed `availableDbs` and `defaultDb` from `n8n` in [`quick-launch-modal.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/components/quick-launch-modal.tsx) so step 3 correctly reflects embedded storage.
  - Added Pest feature tests in [`QuickActionControllerTest.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/tests/Feature/QuickActionControllerTest.php) ensuring `n8n` deploys without any `--db` flag.

---

## 🏛️ Architecture Note: Cluster Tool Initialization

> [!IMPORTANT]
> **Canonical Command Standard**: All cluster companion tools are deployed via:
> ```bash
> larakube tool:init <environment> --tool=<slug>
> # or interactively via
> larakube tool:add --tool=<slug>
> ```
> Per [`cli/plans/active/tool-init-merge.md`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/plans/active/tool-init-merge.md) and [`cli/plans/active/overhaul-tool-categories-to-individual-tools.md`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/plans/active/overhaul-tool-categories-to-individual-tools.md), all legacy category-based init commands (`flow:init`, `data:init`, `chat:init`, etc.) have been phased out.
> The class `FlowInitCommand` in `cli/app/Commands/Flow/FlowInitCommand.php` is strictly an internal abstract base extending `AbstractToolInitCommand` instantiated dynamically by `ToolInitCommands::commandFor($tool)` — it is **never** invoked as a CLI verb.

---

## 🍎 Apple Developer Account & Desktop App Distribution

### Current Status
- The user has registered an Apple ID and initiated enrollment in the paid **Apple Developer Program ($99/year)** on **October 8, 2026**.
- **Pending Window**: Currently waiting up to 24–48 hours for Apple's identity verification and payment confirmation.

### Crucial Architectural Constraint: Mac App Store (MAS) vs Direct Distribution
- **Mac App Store (MAS) Sandbox**: Requires `com.apple.security.app-sandbox`. Sandboxed MAS apps **cannot** spawn unrestricted child processes (`docker`, `k3d`, `kubectl`), communicate with Docker Unix sockets, or manage `/etc/hosts` for `.dev.test` local routing.
- **Industry Standard for Dev Tools**: Like Docker Desktop, OrbStack, TablePlus, Herd, and VS Code, LaraKube Desktop must be distributed **outside the Mac App Store** via **Apple Developer ID-signed and Notarized `.dmg` / `.zip`**.
- **NativePHP Setup**: LaraKube Desktop already has the notarization pipeline configured in [`desktop/nativephp/electron/build/notarize.js`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/nativephp/electron/build/notarize.js) and [`desktop/config/nativephp.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/config/nativephp.php).

### Next Steps When Apple Enrollment Is Approved
1. **Generate Certificate**: In Apple Developer Portal (`Certificates, Identifiers & Profiles`), create a **Developer ID Application** certificate using Mac Keychain Access CSR, download, and install into Keychain.
2. **Retrieve Team ID**: Obtain the 10-character Team ID from Apple Developer Membership details.
3. **App-Specific Password**: Generate an App-Specific Password from `appleid.apple.com` labeled `larakube-desktop-notarize`.
4. **Configure `.env` in `desktop/`**:
   ```env
   NATIVEPHP_APPLE_ID=user@example.com
   NATIVEPHP_APPLE_ID_PASS=xxxx-xxxx-xxxx-xxxx
   NATIVEPHP_APPLE_TEAM_ID=ABC123XYZ4
   ```
5. **Build & Notarize**:
   ```bash
   cd desktop
   php artisan native:build mac
   ```
   Electron-builder will automatically sign with the Keychain certificate, submit to Apple Notary Service (`notarytool`), staple the notarization ticket, and output an install-ready `.dmg`.

---

## 📦 Git & Repository State

### `desktop/` (NativePHP Application)
- **Branch**: `develop`
- **Working Tree**: Clean (`git status` clean)
- **Recent Commits**:
  - `08c0af2`: `fix(dashboard): remove unsupported --db flag from n8n and clean domain suggestions`
  - `3a30a94`: `feat(dashboard): polish quick launch domain dropdown and contextualize companion integrations`
  - `c2866db`: `feat(dashboard): leverage cluster externaldns zones in quick launch wizard`
  - `6ed7f6a`: `feat(dashboard): convert quick launch modal into 4-step wizard`
  - `34f4658`: `fix(dashboard): remove redundant banner buttons and clarify dns prerequisites`
  - `4653b86`: `feat(dashboard): integrate 1-click companions into top banner and fix health score gauge color`
  - `4e16b89`: `fix(dashboard): balance grid layout and fix onboarding redundancy`
  - `1e72405`: `fix(dashboard): remove uptime kuma and streamline dashboard layout`
- **CI / Quality Checks**:
  - `vp check` / `vp check --fix`: 0 warnings, 0 errors.
  - `tsc --noEmit`: Passes cleanly.
  - `pint --parallel --test`: Passes cleanly.
  - `phpstan analyse`: Passes cleanly (0 errors).
  - `php artisan test --parallel`: 397/397 tests passing (2,277 assertions).

### `cli/` (LaraKube Core Engine)
- **Branch**: `develop`
- **Working Tree**: Clean (`git status` clean)
- **Recent Commits**:
  - `dbbfe24f`: `feat(tools): support select choices and conditional options in tool init specs`
  - `4c9770e4`: `feat(tool): standardize commons capabilities and add 1-click wordpress companion`
- **Rule Reminder**: AI agents are **strictly forbidden** from running `./build`. If a binary recompile is needed, instruct the human operator.

---

## 🚀 Recommended Next Actions
1. **Test 1-Click Launch**: Open Desktop in development mode (`composer dev` or `php artisan dev`) and test the 4-step wizard with `n8n`, `wordpress`, and `pocketbase`.
2. **Monitor Apple Developer Approval**: Once approved (email notification), configure the 3 `.env` keys and run the first notarized macOS build.
3. **Distribution CI**: If distributing via GitHub Releases or S3/Spaces, verify the updater configuration in `desktop/config/nativephp.php`.
