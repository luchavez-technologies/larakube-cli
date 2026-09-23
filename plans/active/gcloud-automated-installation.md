# Implementation Plan: `CliTool` Enum, Smart `SetupCommand` & GCP `gcloud` Automation

**Status:** ✅ COMPLETED & VERIFIED IN PRODUCTION BUILD  
**Target Commands:** `setup`, `cloud:create`, `cloud:scale`

---

## 🎯 Executive Summary & Context

To support Google Cloud Platform seamlessly alongside existing tools, eliminate throw-away Docker container overhead, and prevent accidental k3s/system-package re-installations:
1. We introduce an **`App\Enums\CliTool`** enum representing developer and cloud CLI dependencies (`k9s`, `tofu`, `gcloud`, `gh`, `tea`).
2. We make **`SetupCommand` (`larakube setup`)** state-aware:
   - It checks existing components (runtime, k3s, Traefik, dnsmasq).
   - Any already-functional component is marked with `✓` and **skipped**.
   - `apt update` is **strictly isolated** to first-time container runtime installations (Podman/Docker) on Linux/WSL, and is **NEVER executed for client tools**.
3. In interactive mode, `setup` presents an **Interactive Tools Multiselect** showing green checkmarks (`✓`) for installed tools and letting users select missing tools (`k9s`, `tofu`, `gcloud`, `gh`, `tea`).
4. The **`--tool=<slug>` / `--tools=<slugs>`** flags allow targeted tool installations without running cluster/runtime setup.
5. In **`cloud:create`**, if GCP is chosen and `gcloud` is missing, it asks the user if they'd like to install it now via `setup --tool=gcloud`, executes automated browser authentication (`gcloud auth application-default login`), and pre-fills the Project ID prompt from `gcloud config get-value project`.
6. For **`gh`** and **`tea`**, native installation eliminates the throw-away Docker fallbacks (`alpine:latest` and `gitea/tea:latest`), removing multi-second container startup delays.

---

## 📦 User Experience & Flow

### 1. Smart Interactive `larakube setup` Flow
Running `larakube setup` detects your current environment state:

```
LaraKube Environment Setup

  ✓ Container runtime (Podman) is functional.
  ✓ Local Kubernetes cluster (k3s) is running.
  ✓ Traefik ingress is active.
  ✓ Wildcard DNS (dnsmasq) is configured.

Which CLI & developer tools would you like to install?
  ✓ k9s — (already installed at /usr/local/bin/k9s)
  ✓ OpenTofu — (already installed at /opt/homebrew/bin/tofu)
  [ ] Google Cloud SDK (gcloud) — For GCP Compute Engine & GKE clusters
  [ ] GitHub CLI (gh) — Native GHCR container registry & CI management
  [ ] Tea CLI (tea) — Native Forgejo / Gitea git forge management
```
- Only uninstalled tools are presented for selection.
- `apt update` is **never run** unless a fresh Podman or Docker install was actually performed.
- If `gcloud` is selected and newly installed, it prompts:
  `Would you like to log in to Google Cloud now via browser?` and runs `gcloud auth application-default login`.

### 2. Targeted Shortcut (`setup --tool=<name>`)
- Running `larakube setup --tool=gcloud` or `larakube setup --tools=tofu,gh`:
  - Skips Docker runtime, k3s cluster, Traefik, dnsmasq, and apt update entirely.
  - Installs only the requested tool(s) and exits `0` in seconds.
  - Enables commands like `cloud:create` to cleanly install missing dependencies via `$this->call('setup', ['--tools' => 'gcloud'])`.

---

## 🏛️ Proposed Architecture & Component Changes

### 1. New Enum: `App\Enums\CliTool`
**File:** `cli/app/Enums/CliTool.php`

- **Cases:**
  - `K9S = 'k9s'`
  - `TOFU = 'tofu'`
  - `GCLOUD = 'gcloud'`
  - `GH = 'gh'`
  - `TEA = 'tea'`
- **Methods:**
  - `label(): string`
  - `description(): string`
  - `binary(): string`
  - `isDefault(): bool` (true for `k9s` and `tofu`)
  - `candidatePaths(): array` (PATH, `/opt/homebrew/bin`, `/usr/local/bin`, `~/.larakube/bin`, `~/google-cloud-sdk/bin`)
  - `resolveBinary(): ?string`
  - `isInstalled(): bool`
  - `install(): bool` (Homebrew on macOS; user-space script or release binary on Linux/WSL2 — **NO apt updates**)

---

### 2. Update: `App\Commands\SetupCommand`
**File:** `cli/app/Commands/SetupCommand.php`

- **Signature:**
  ```php
  protected $signature = 'setup
      {--runtime= : Container runtime to install without prompting (podman or docker)}
      {--tool=*   : Install one or more specific CLI tools without running full setup (e.g. --tool=gcloud)}
      {--tools=   : Comma-separated list of CLI tools to install (e.g. --tools=gcloud,tofu)}';
  ```
- **Flag Shortcut Path (Early Exit):**
  - If `--tool` or `--tools` is provided:
    - Parses requested tools into `CliTool` cases.
    - If unknown slug: fails with list of valid options.
    - Installs the requested tools directly and exits.
- **Smart Component Detection:**
  - Checks if runtime (Podman/Docker) is functional. If so, skips runtime prompt.
  - Checks if k3s/Kubernetes is reachable. If so, skips `cluster:setup`.
  - Checks if Traefik is running. If so, skips `traefik:setup`.
- **Interactive Tools Step:**
  - Iterates over `CliTool::cases()`:
    - Displays green checkmarks for already-installed tools.
  - For missing tools:
    - Displays `multiselect(label: 'Which CLI & developer tools would you like to install?', options: $missingOptions, default: $defaultMissing)`.
    - Installs newly selected tools.
    - If `gcloud` was newly installed: offers browser login via `gcloud auth application-default login`.

---

### 3. Update: `App\Commands\Cloud\CloudCreateCommand` & `CloudScaleCommand`
**Files:** `cli/app/Commands/Cloud/CloudCreateCommand.php`, `cli/app/Commands/Cloud/CloudScaleCommand.php`

- In `ensureGcpCredentials()`:
  - If `CliTool::GCLOUD->isInstalled()` is false and no `--gcp-credentials` provided:
    - Non-interactive: errors pointing to `larakube setup --tool=gcloud`.
    - Interactive: prompts `Google Cloud CLI (gcloud) is not installed. Would you like to install it now via larakube setup?`
      If confirmed: `$this->call('setup', ['--tools' => 'gcloud']);`
  - Verifies auth (`gcloud auth print-access-token`):
    - If unauthenticated, prompts to run `gcloud auth application-default login`.
  - Prefills Project ID prompt with `gcloud config get-value project`.

---

### 4. Update: `App\Traits\InteractsWithGlobalConfig` (Native `gh` & `tea`)
**File:** `cli/app/Traits/InteractsWithGlobalConfig.php`

- Add `home_path('.larakube/bin/gh')` and `home_path('.larakube/bin/tea')` to candidate paths in `getGhCommand()` and `getTeaCommand()` before falling back to containerized execution.

---

## 🧪 Verification Plan

### Automated Tests
1. **Unit Test:** `cli/tests/Unit/CliToolTest.php`
   - Test enum cases, defaults, binary names, candidate paths, and resolution.
2. **Feature Test:** `cli/tests/Feature/SetupToolFlagTest.php`
   - Test `setup --tool=gcloud` installs only the targeted tool and skips cluster/runtime/apt setup.
   - Test `setup --tools=tofu,gh` parses comma-separated lists.
   - Test smart detection skips already functional components.
3. **Feature Test:** `cli/tests/Feature/CloudCreateGcpTest.php`
   - Test `cloud:create` delegates to `setup --tool=gcloud` when `gcloud` is missing.
   - Test project ID prefill from `gcloud config get-value project`.

### Code Quality & Static Analysis
```bash
php vendor/bin/pint --test
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
php vendor/bin/pest tests/Unit/CliToolTest.php tests/Feature/SetupToolFlagTest.php tests/Feature/CloudCreateGcpTest.php
```
