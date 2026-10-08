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

### Status: APPROVED & FULLY CONFIGURED 🎉
- **Account Approved**: James Carlo Luchavez (`7UR8RL4U6Q`).
- **Certificate**: Created **Developer ID Application** certificate using the **G2 Sub-CA (Xcode 11.4.1 or later)**.
- **Keychain Trust Chain**: Downloaded and imported Apple's official `Developer ID - G2 CA` intermediate certificate into macOS Keychain Access.
- **Identity Verified**:
  ```
  1) 22FE61F18493C953ECA6F100D02473E8DA912753 "Developer ID Application: James Carlo Luchavez (7UR8RL4U6Q)"
     1 valid identities found
  ```
- **Local `.env`**: Configured `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS`, and `NATIVEPHP_APPLE_TEAM_ID` in `desktop/.env` (verified 100% gitignored).
- **GitHub Repository Secrets**: Added all 5 repository secrets to `luchavez-technologies/larakube-desktop`:
  - `CSC_LINK` (base64 `.p12` bundle)
  - `CSC_KEY_PASSWORD` (p12 decryption password)
  - `NATIVEPHP_APPLE_ID` (`jamescarloluchavez@icloud.com`)
  - `NATIVEPHP_APPLE_ID_PASS` (App-Specific Password)
  - `NATIVEPHP_APPLE_TEAM_ID` (`7UR8RL4U6Q`)
- **CI/CD Workflow Committed**: Updated [`desktop/.github/workflows/release.yml`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/.github/workflows/release.yml) in commit `2fc9230`:
  - Scoped secrets to `macos-latest` runners (`${{ matrix.target == 'mac' && secrets.CSC_LINK || '' }}`) so Windows builds don't fail.
  - Passes credentials into `.env` and runner environment for `native:build` to sign and notarize.
  - Both Canary (`develop`) and stable (`v*`) releases are signed and notarized by Apple.

---

## 📦 Git & Repository State

### `desktop/` (NativePHP Application)
- **Branch**: `develop`
- **Recent AI Commits**:
  - `2fc9230`: `ci(release): wire Apple code signing and notarization secrets for macOS`
  - `08c0af2`: `fix(dashboard): remove unsupported --db flag from n8n and clean domain suggestions`
- **Active Working Tree (Disregarded for User)**:
  - User is actively developing server/activity/mail models and migrations (`app/Models/*`, `database/migrations/*`, `tools/show.tsx`, `mail/index.tsx`). Left completely untouched and uncommitted.

### `cli/` (LaraKube Core Engine)
- **Branch**: `develop`
- **Working Tree**: Contains uncommitted work on `app/Commands/Data/DataRemoveCommand.php` and `tests/Feature/PocketBaseDataInitTest.php` for Claude to continue.

---

## 🚀 Recommended Next Actions
1. **Push to GitHub**: Running `git push origin develop` in `desktop/` will trigger the first automated cloud build with Apple code signing and notarization on GitHub Actions!
2. **Continue Local Feature Work**: Claude can continue right where it left off on the desktop tool/mail views and migrations.
