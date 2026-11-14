# Plan: Refactor CLI Tools into Per-Tool Service Classes (`CliToolContract` + `AbstractCliTool` + `CliToolRegistry`) with Reinstall & Corrupted Binary Recovery

## Context & Goal
Replace scattered per-tool conditionals across `ReadinessCheck`, `ToolInstallController`, `ToolLocator`, and `CliInstaller` with a clean **Service class per CLI tool** architecture (`app/Contracts/CliToolContract.php`, `app/Services/LaraKube/Tools/AbstractCliTool.php`, `app/Services/LaraKube/Tools/AbstractDirectCliTool.php`, `app/Services/LaraKube/Tools/CliToolRegistry.php`, and 14 concrete tool classes), while fixing Windows CLI download hangs, corrupted binary detection/reinstall, and false WSL diagnostics.

---

## Implementation Steps

### Step 1: Save Plan in `cli/plans/active/cli-reinstall-and-corrupted-binary-recovery.md`
- Persist this plan in `cli/plans/active/cli-reinstall-and-corrupted-binary-recovery.md`.

### Step 2: Create Per-Tool Service Architecture
1. `desktop/app/Contracts/CliToolContract.php`
2. `desktop/app/Services/LaraKube/Tools/AbstractCliTool.php`
3. `desktop/app/Services/LaraKube/Tools/AbstractDirectCliTool.php`
4. 14 concrete tool service classes under `desktop/app/Services/LaraKube/Tools/`:
   - `LaraKubeCliTool`, `LaraKubeWslCliTool`, `KubectlTool`, `K9sTool`, `OpenTofuTool`, `SshTool`, `AwsCliTool`, `GcloudCliTool`, `GhCliTool`, `TeaCliTool`, `GitTool`, `DockerTool`, `PodmanTool`, `DnsmasqTool`
5. `desktop/app/Services/LaraKube/Tools/CliToolRegistry.php`

### Step 3: Wire `CliInstaller`, `ReadinessCheck`, and `ToolInstallController`
1. `desktop/app/Services/LaraKube/CliInstaller.php`:
   - `set_time_limit(0)` on `install()` and `installWsl()`.
   - Remove redundant Linux cache download from Windows `install()`.
   - `downloadVerifiedBinary()` using fresh same-directory temp files, PSR-7 stream closing, `clearstatcache()`, `Content-Length` verification, and safe retries.
2. `desktop/app/Services/LaraKube/ReadinessCheck.php`:
   - Delegate `catalog()` and `status()` lookup/locate/versionArgs to `CliToolRegistry` (`locate()` on Windows host tools never falls back to `findInDistro()`).
3. `desktop/app/Http/Controllers/ToolInstallController.php`:
   - Delegate `store()`, `updateTool()`, and `updateCli()` to `CliToolRegistry` + `dispatchInstall()`, with `reinstall` verb support.

### Step 4: Frontend — Corrupted Binary State & Reinstall Button
1. `desktop/resources/js/lib/tool-status.ts`: add `toolIsHealthy()` and update `toolIsInstalled()` to treat corrupted binaries (`installed && version === null && diagnostic`) as unhealthy.
2. `desktop/resources/js/components/setup/tool-catalog.tsx`: show `Corrupted` badge, diagnostic prompt, primary `Reinstall` button when corrupted, and `Reinstall` button alongside `Update` on healthy `larakube` / `larakube-wsl` rows.

### Step 5: Tests, Verification, Commit & Push
1. Add/update tests in `desktop/tests/Feature/CliInstallerTest.php`, `desktop/tests/Feature/UpdateCliTest.php`, and `desktop/tests/Feature/ReadinessTest.php`.
2. Run `npx vp check --fix && npx tsc --noEmit`.
3. Run `composer test`.
4. Commit and push both `desktop/` and `cli/` to `origin/develop`.
