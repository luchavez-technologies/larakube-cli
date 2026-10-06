# LaraKube Desktop on macOS: tools, signing, auto-update

**Status:** Proposal (2026-10-06). Nothing built.
**Builds on:** `larakube-desktop-feasibility.md` (macOS section), `desktop/plans/active/04-packaging-signing-updates.md`, `desktop/plans/active/10-windows-installer-with-wsl.md`, ADR 0001/0002/0007 in `desktop/docs/decisions/`.

## Where we are (from the code and the desktop commits)

- The Mac build already works end to end for the remote profile (GCP `cloud:create` ran from the app). It is **unsigned, with auto-update off**: `release.yml` sets `NATIVEPHP_UPDATER_ENABLED=false` for `mac`, and release notes tell users to right-click → Open.
- Windows solved "no tools on the machine" by shipping its own distro (`larakube-ubuntu`). macOS has no equivalent, so Setup shows per-tool Install buttons for the LaraKube CLI, kubectl, OpenTofu, AWS CLI, gcloud and Podman.
- `CliInstaller` already installs the CLI sudo-free into `~/.larakube/bin`. `ToolLocator::directories()` already searches `~/.local/bin`, `~/.larakube/bin`, `/opt/homebrew/bin`, `~/google-cloud-sdk/bin` and more, and hands that PATH to every child process.
- The CLI's own installers (what the Setup buttons run) prefer Homebrew and fall back to paths that need `sudo`. The app has no terminal for a password.

## Verified facts (live, 2026-10-06)

| Topic | Finding |
|---|---|
| Homebrew needs developer tools | **Yes.** It needs the Xcode Command Line Tools (CLT, not full Xcode). The official `install.sh` detects missing CLT and triggers Apple's GUI installer (same as `xcode-select --install`), then asks for the Mac password. The CLT download is large and slow on bad Wi-Fi. |
| Homebrew `.pkg` installer | `docs.brew.sh/Installation` lists a `.pkg` (Apple Silicon only). A search says it does **not** install CLT; the docs don't say either way. Verify on a clean Mac before relying on it. |
| Homebrew OS support | macOS 15 (Sequoia) or newer on supported hardware. Intel is Tier 3. **An older MacBook can't use supported Homebrew at all.** |
| `git` on a fresh Mac | `/usr/bin/git` exists as a shim that pops the CLT install dialog. `ToolLocator` finds `/usr/bin/git` and reports Git as installed, so Setup is wrong on a Mac without CLT. Check `xcode-select -p`, not the file. |
| AWS CLI v2, no sudo | The `.pkg` supports per-user install: `installer -pkg AWSCLIV2.pkg -target CurrentUserHomeDirectory -applyChoiceChangesXML choices.xml`, then symlink into `~/.local/bin`. (A search also found a `v2/install.sh` script; unconfirmed, don't use it until verified.) |
| gcloud, no sudo | The `.tar.gz` extracts into `~` and ships its own Python. The "needs a recent Python" problem is a Homebrew-path problem, not a tarball one. Verify the bundled Python on the current archive. |
| Podman on macOS | `ResolvesContainerRuntime::containerRuntime()` **always returns `docker` on macOS**, because OrbStack/Docker Desktop stores can't see Podman images. So even installing Podman by hand on a Mac gives a runtime the CLI will not use. Podman on Mac is a CLI change, not a Setup polish item. |
| macOS signing | NativePHP reads `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS` (app-specific password) and `NATIVEPHP_APPLE_TEAM_ID`; without notarization other Macs show "app is damaged". Needs a paid Apple Developer account and a "Developer ID Application" certificate. |
| macOS auto-update | Works only for signed + notarized builds; electron-updater needs the `.zip` plus its feed (`latest-mac.yml`). Separate arm64 and x64 builds both write `latest-mac.yml` and overwrite each other. |

## Personas & Scope

1. **Non-dev Operator (Primary Target for Desktop):**
   - Goal: Provision cloud servers (DO / Hetzner / AWS / GCP) and install/manage Cluster Tools (Vaultwarden, Uptime Kuma, NetBird, Nextcloud, etc.).
   - Needs: Only `larakube` CLI, `kubectl`, `tofu`, and provider credentials.
   - Does NOT need: Docker, OrbStack, Podman, Git, Composer, npm, Node.
   - Expectation: Zero terminal interaction. First launch configures tools silently in the background.

2. **Developer:**
   - Goal: Local Laravel / framework application development and deployments.
   - Can use existing OrbStack/Docker Desktop if present.
   - Optional: "Install in Terminal" button to add `~/.larakube/bin` to `~/.zshrc`.

## Decision 1: drop Homebrew as a requirement (Zero-Terminal Model)

Homebrew is terminal-first, asks for admin passwords, and requires a 1.5GB+ Xcode Command Line Tools download — causing 80%+ drop-off for non-devs. Successful Mac desktop apps (GitHub Desktop, Lens, OrbStack) never require Homebrew; they bundle or download static binaries directly.

The Mac foundation is **app-managed, user-local static binaries** in `~/.larakube/bin`:

| Tool | Size | Mac install (Zero sudo, Zero Homebrew) |
|---|---|---|
| larakube | ~35 MB | Download to `~/.larakube/bin/larakube` (`CliInstaller`) |
| kubectl | ~50 MB | Static binary from `dl.k8s.io` directly into `~/.larakube/bin/kubectl` |
| tofu | ~60 MB | Official `.tar.gz` from GitHub releases directly into `~/.larakube/bin/tofu` |
| aws | ~40 MB | AWS CLI v2 user-local install (`XDG_BIN_HOME=~/.larakube/bin`) |
| gcloud | ~100 MB | Extract tarball to `~/google-cloud-sdk` (on demand when GCP is used) |
| Homebrew | - | Strictly optional. If present on PATH, `ToolLocator` detects it and uses it. Never required. |

Total download for non-devs: ~150 MB (just `larakube` + `kubectl` + `tofu`).

### Version Management & Updates
- Maintain pinned, verified versions in the manifest.
- When LaraKube Desktop updates, it checks for updated tool versions and downloads new binaries in the background without package manager conflicts.


## Decision 2: local development on a Mac

"Set up local development" is out of reach without a terminal (administrator checks, hosts/DNS, trust). Options:
- **A. Remote profile only on Mac for v0.0.1 (recommended).** Cloud servers, Cluster Tools, and CI deploys (plan 02). No local cluster, no Podman, no container runtime. Hide Docker/Podman rows on Mac unless the project path needs them.
- **B. Local dev via the user's own OrbStack/Docker Desktop.** Setup only reports it. Works today for anyone who already has it.
- **C. Podman on Mac.** Requires changing `containerRuntime()` plus the image-sideload path (`localImageRef`), starting `podman machine`, and a way to prove k3d/k3s can see the images. Treat it as its own spike, not part of this plan.

Recommended: A + B. `ReadinessCheck::catalog()` already offers the Podman button only on Linux (`PHP_OS_FAMILY === 'Linux'`), so there is **no Podman button on a Mac today**, contrary to the earlier conversation. Keep it that way until the Decision 2C spike is done.

## Signing, notarization and auto-update

### What the Apple account needs
1. Apple Developer Program membership ($99/yr). Decide: personal or company (Luchavez Technologies) enrollment. Company enrollment needs a D-U-N-S number and takes longer; start early.
2. A **Developer ID Application** certificate, exported as `.p12`.
3. An **app-specific password** for the Apple ID, and the Team ID.

### CI changes (`desktop/.github/workflows/release.yml`)
- Store as repo secrets: the `.p12` (base64) and its password, `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS`, `NATIVEPHP_APPLE_TEAM_ID`.
- In the mac matrix jobs, import the certificate into a temporary keychain before `native:build`, and export the vars. Keep them out of the app: `config/nativephp.php` `cleanup_env_keys` already strips the `NATIVEPHP_APPLE_*` keys.
- **Fail loudly.** NativePHP only logs "appleId property is required" and carries on, and `native:build` exits 0 even when Electron's build fails (known trap). After the build, run `codesign --verify --deep --strict`, `spctl --assess --type execute` and `xcrun stapler validate` on the `.app`; fail the job if any fails.
- Hardened runtime and entitlements for the bundled static PHP and Electron helpers (JIT/unsigned-executable-memory may be needed). Spike this with a real notarization run; read Apple's rejection log.

### Auto-update
- Flip `NATIVEPHP_UPDATER_ENABLED` to true for mac once the build is notarized.
- **Fix the feed collision.** NativePHP's build command accepts only `x64` and `arm64` for macOS (`OsAndArch.php`), so there is no supported universal build. Keep the two existing mac jobs and publish a feed per architecture (e.g. `latest-mac-arm64.yml`, `latest-mac-x64.yml`), with the app choosing its feed from `process.arch` in `desktop/nativephp/electron`. Estimate 1–2 days, mostly testing on a signed build on both Intel and Apple Silicon. Check first whether NativePHP's config exposes the feed URL; if not, patch its Electron code.
- `release.yml` already uploads installers first and feed files last, and never deletes the canary release. Keep that, and add `latest-mac.yml` to the "Collect installers" step (it deliberately skips it today).
- The in-app updater UI exists (`AppUpdates`, Settings card). Test the full loop: install canary N (signed), publish canary N+1, app downloads and restarts into N+1. This can only be verified on a signed build, on a Mac that is not the build machine.
- Channels: canary users read the `canary` release, stable users read `latest`. Reuse `LARAKUBE_UPDATE_URL`.
- Interim for friends and teammates: unsigned builds with the `xattr -dr com.apple.quarantine` workaround and manual re-download.

## Setup page work (Desktop, `desktop/`)
1. `Diagnostics`/`ReadinessCheck`: on macOS, check `xcode-select -p` for Git, and show the "Install Apple developer tools" button.
2. Install buttons call the sudo-free CLI installs; show the CLI's real error when one fails, as the Windows path does.
3. Show the Mac-specific rows in order: LaraKube CLI → kubectl → OpenTofu → provider CLI (only the chosen one) → Git/CLT. Hide Docker/Podman unless local development is selected.
4. Homebrew notice with Copy only as the fallback above.
5. Tests in `desktop/tests/Feature/` for the readiness rows (fake `xcode-select`, a Mac `ToolLocator`) and for the installer arch/URL selection.

## Order of work
0. **Spike on a clean Mac (half a day).** A fresh macOS user or VM: install each tool user-local by hand (kubectl, tofu, aws per-user pkg, gcloud tarball), confirm the app finds them via `ToolLocator`, confirm Git/CLT behaviour, and try the Homebrew `.pkg`. Record results here.
1. CLI: `LARAKUBE_BIN_DIR` and sudo-free installs for kubectl/tofu/aws/gcloud (`cli/`, with tests). User runs `./build`.
2. Desktop Setup changes above.
3. Apple Developer enrollment + certificate (start in parallel; it has the longest lead time).
4. Signed + notarized canary; verify with `spctl`/`stapler` on a second Mac.
5. Auto-update loop test; flip the mac updater on.
6. Tag `v0.0.1`.

## Decisions (2026-10-06 interview)
1. **Scope:** cloud servers and Cluster Tools, plus local development through the user's own OrbStack or Docker Desktop (Setup only reports it). Podman on Mac (Decision 2C) is out of scope.
2. **Tools:** app-managed, user-local installs. No Homebrew, no password.
3. **Apple enrollment:** start as **Individual** (LuchTech Web Development Services is a DTI/BIR sole proprietorship, not a separate legal entity; see below).
4. **Builds:** keep the existing separate arm64 and x64 mac builds, with a per-architecture update feed (changed from "universal": NativePHP does not support a universal build).
5. **Test Mac:** macOS 15 or newer, so Homebrew would work there, but we still don't depend on it.

## Apple enrollment: sole proprietor now, company later
- Apple's **Organization** enrollment needs a legally recognized entity and a D-U-N-S number. A sole proprietorship with a DTI business name is not a separate legal entity, and Apple does not accept DBAs or trade names for it. So a DTI/BIR registration does **not** qualify; enroll as **Individual** (Apple's category for sole proprietors).
- Consequence: Gatekeeper and the Developer ID certificate show your **personal legal name**, not "LuchTech". Users never see the business name on the app's signature.
- Switching later: a search says Apple Developer Support can convert an Individual account to an Organization once you incorporate (new registration documents, D-U-N-S). Unverified from Apple itself; confirm with Apple before depending on it.
- **The real cost of switching is auto-update.** Squirrel.Mac only accepts an update whose signature satisfies the installed app's designated requirement, which includes the Team ID. If the Team ID or certificate changes, existing installs reject updates. Fix: ship a "pivot" release, still signed with the old identity, whose designated requirement accepts both old and new Team IDs (`mac.requirements` in electron-builder), then move to the new one. Without it, users must reinstall by hand. Unknown: whether a converted account keeps its Team ID. Ask Apple, and check NativePHP exposes `mac.requirements`.
- Recommendation: enroll as Individual now (days, not weeks). Design the build so the designated requirement can be widened later. Do not wait for incorporation to ship v0.0.1.

## Still open
1. Whether NativePHP exposes the updater feed URL, or its Electron code must be patched (per-arch feed).
2. Whether the Homebrew `.pkg` installs the Command Line Tools (only matters for the optional fallback).
3. Whether a converted Apple account keeps its Team ID.


## Signing: Apple Developer (macOS) and SignPath (Windows)

**Decision:** Apple Developer Program for macOS; SignPath Foundation for Windows. This replaces the Azure Trusted Signing assumption in `desktop/plans/active/04-packaging-signing-updates.md` and `10-windows-installer-with-wsl.md`.

| | macOS | Windows |
|---|---|---|
| Provider | Apple Developer Program, Individual | SignPath Foundation (free, open source) |
| Cost | $99/yr | $0 |
| Gates | Gatekeeper and auto-update | SmartScreen warning only |
| Why not the alternative | n/a, Apple is the only option | Azure Artifact Signing ($9.99/mo Basic) is **not available to the Philippines**: Microsoft's docs limit individuals to the US and Canada, and the organization list excludes the Philippines. Public Trust only; Private Trust has no country limit but isn't trusted on other PCs. |
| Fallback | none | Certum Open Source code signing (~$100/yr, unverified) |
| Not bundled | There is no single service that signs both. They are separate trust systems. |

### Why SignPath should qualify
SignPath Foundation requires an OSI-approved license, a public repo, free downloads, and a binary built from that public repo. Desktop is MIT, public, and free to download. The paid-or-free decision for Cluster Tools is made by the **CLI and LaraKube Cloud**, not by Desktop, so Desktop ships no paid code. Whether SignPath accepts that open-client/paid-backend model is their call, so describe it plainly in the application. Their summary says the free service is not for signing commercial software; ask rather than assume.

### Rules that keep Desktop eligible
- Desktop must not embed license enforcement or paid-feature code. It may display what the CLI reports (for example "needs a plan"); the CLI contacts Cloud for entitlements.
- The CLI binary Desktop downloads is a separate product and is not signed by SignPath. On Windows it runs inside the WSL distro and needs no Windows signature. On macOS, sign it in the CLI release pipeline.
- Builds to be signed must come from the public repo's CI (GitHub Actions), as SignPath verifies the build origin.
- **Padlock icons (decided 2026-10-06):** Desktop may show a padlock on paid features, using entitlement info the CLI gets from LaraKube Cloud. Enforcement stays in the CLI and Cloud, never in Desktop. Whether the CLI stays open source is undecided (Cloud isn't built yet); mention that honestly to SignPath.
- **Apple ID (decided):** a new Apple ID dedicated to the developer account, with two-factor on. Use an email you will keep permanently, because the Team ID is tied to the account and changing it later breaks auto-update (see the enrollment section). Its legal name is what Gatekeeper shows.

### Done
- Added `desktop/LICENSE` (MIT, `LuchTech Web Development Services`, copied from `cli/LICENSE`), so the repo's declared MIT license has a file.

### To do
1. Apply at https://signpath.org/apply.html (vetting; replies can be slow, so apply early). Do not block `v0.0.1` on it: Windows stays unsigned until approved.
2. Enroll in the Apple Developer Program as Individual, then create the Developer ID Application certificate.
3. When SignPath approves: wire its GitHub Action into the Windows job in `release.yml` and remove the Azure variables from `config/nativephp.php` `cleanup_env_keys`, if not needed. Check how NativePHP's `native:build win` hands the `.exe` to an external signer; NativePHP documents only Azure and certificate-based signing, so this may need a post-build signing step on the produced installer.
4. If SignPath declines: Certum (check CI compatibility of its cloud signing first).

## Sources
- https://learn.microsoft.com/en-us/azure/artifact-signing/quickstart (country eligibility)
- https://signpath.org/apply.html

- https://docs.brew.sh/Installation
- https://brew.sh
- https://nativephp.com/docs/desktop/2/publishing/building
- https://nativephp.com/docs/desktop/2/publishing/updating
- https://www.electron.build/code-signing and https://www.electron.build/auto-update
- AWS CLI install docs (per-user `.pkg`, `-target CurrentUserHomeDirectory`)
- https://cloud.google.com/sdk/docs/install
