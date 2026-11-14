# Plan: Windows Host vs WSL Execution Context & Windows `cmd.exe` Compatibility

## Root Cause Summary
1. **`WslBridge::shouldDispatchToWsl()` inverted routing (`cli/app/Services/WslBridge.php`)**:
   - `WslBridge` used a tiny 27-command `NATIVE_WINDOWS_COMMANDS` allowlist and forwarded **every other CLI command** (`dns:verify`, `tool:list`, `tool:catalog`, `tool:show`, `tool:pause`, `tool:resume`, `tool:env`, `tool:env:set`, `tool:proxy`, `tool:unproxy`, `tool:alias`, `plex:*`, `mail:*`, `openbao:*`, `secrets:*`, `sso:*`, `zitadel:*`, `ocis:*`, `sheet:*`, `drive:*`, `cluster:users`, `cluster:grant`, `cluster:revoke`, `devbox:*`, `cloud:init`, `cloud:configure`, `cloud:deploy`, `cloud:harden`, `cloud:provision*`, `external-dns:remove`, `tls:prune`, `backup:list`, `backup:status`, `backup:check`, `snapshot:*`, `dashboard:*`, `traefik:*`, `config:*`, `new:frameworks`, `version`, and all 45+ `<tool>:*` commands) into `wsl.exe -d larakube-ubuntu`.
   - When the user clicked **Verify token** on Windows in `Servers -> Create server -> Step 3: DNS & SSL Automation (Cloudflare)`, `CloudflareController::verifyToken()` executed `larakube.exe dns:verify --provider=cloudflare --token=... --json --no-interaction`. Because `dns:verify` was not in `NATIVE_WINDOWS_COMMANDS`, `larakube.exe` dispatched it to `wsl.exe -d larakube-ubuntu`, which failed with a non-JSON error when WSL/`larakube-ubuntu` was not set up, triggering `"Invalid response from LaraKube CLI verification."`.
2. **POSIX-only shell constructs in `cli/app/` that break under Windows `cmd.exe`**:
   - Trailing `2>/dev/null` or `>/dev/null 2>&1` causes `cmd.exe` to fail with `"The system cannot find the path specified."` (exit code 1, empty stdout).
   - Single-quoted `-o jsonpath='...'` passes literal `'` characters to `kubectl.exe` under `cmd.exe`, wrapping output in `'...'` and breaking `base64_decode(..., true)` / `json_decode()`.
   - `BackupStatusCommand` uses `command -v aws` instead of `CliTool::AWS->isInstalled()`.
3. **Desktop Windows Host vs WSL Context Issues (`desktop/app/`)**:
   - `ToolLocator::isolateForNativeWindows()` should set `LARAKUBE_EXECUTION_CONTEXT=host` by default so host-dispatched `larakube.exe` invocations explicitly declare host context unless a WSL project `cwd` is used.
   - `CloudflareController::verifyToken()` should strip NUL bytes (`\0`) before parsing output lines, pass `['LARAKUBE_EXECUTION_CONTEXT' => 'host']`, and surface a clear error message if the CLI binary fails or outputs a non-JSON error (while keeping `code: 'invalid_response'` and HTTP 500 for `CloudflareTest`).
   - `SshKeyInspector` should fall back to `ToolLocator::hostProfile()` when `HOME` is empty so `~/.ssh/...` keys resolve on Windows (`USERPROFILE`).

---

## Ordered Implementation Steps

### Step 1: Save Plan Artifact in `cli/plans/active/windows-host-vs-wsl-execution-context.md`
- Write this plan to `cli/plans/active/windows-host-vs-wsl-execution-context.md` per the Plan Artifact Location Standard.

### Step 2: Invert `WslBridge::shouldDispatchToWsl()` to Explicit Local-WSL Routing
- **File**: `cli/app/Services/WslBridge.php`
  - Replace the narrow opt-in `NATIVE_WINDOWS_COMMANDS` routing with explicit local-WSL routing (`WSL_LOCAL_COMMANDS` and `WSL_LOCAL_ENV_COMMANDS`).
  - Route all other commands (`dns:*`, `cloud:*`, `tool:*`, `tls:*`, `plex:*`, `mail:*`, `openbao:*`, `secrets:*`, `sso:*`, `zitadel:*`, `ocis:*`, `sheet:*`, `drive:*`, `backup:*`, `snapshot:*`, `context*`, `cluster:users`, `cluster:grant`, `cluster:revoke`, `devbox:*`, `dashboard:*`, `traefik:*`, `vpn:*`, `storage:*`, `config:*`, `new:frameworks`, `setup`, `update`, `version`, `wsl:*`, and all 45+ `<tool>:*` commands) natively on Windows host.
  - Respect `LARAKUBE_EXECUTION_CONTEXT=host`, `LARAKUBE_NO_WSL=1`, and non-local `--context=` flags.
- **File**: `cli/tests/Unit/WslBridgeTest.php`
  - Update existing tests and add assertions verifying native host vs local WSL dispatch behavior.

### Step 3: Fix Windows `cmd.exe` Shell Hazards Across `cli/app/`
1. Remove `2>/dev/null` / `>/dev/null 2>&1` and `| grep` on local host commands across `InteractsWithToolRegistry`, `InteractsWithSecrets`, `InteractsWithClusterIdentity`, `SharedClusterService`, `ClusterUsersCommand`, `CloudProvisionDoksCommand`, `StalwartInitCommand`, and `SsoWireCommand`.
2. Replace single-quoted `-o jsonpath='...'` with `'-o jsonpath='.escapeshellarg(...)` across `cli/app/`.
3. Replace `command -v aws` in `BackupStatusCommand` with `CliTool::AWS->isInstalled()`.

### Step 4: Harden Desktop Windows Host Context & Error Reporting (`desktop/app/`)
- **Decision**: Do **not** set `LARAKUBE_EXECUTION_CONTEXT=host` unconditionally in `ToolLocator::isolateForNativeWindows()`, because Desktop's `ToolLocator::find('larakube')` returns `larakube.exe` for WSL-bound local-development commands too (`cluster:setup`, `up`, `down`, `art`). Instead, `WslBridge` handles command + environment routing, while explicit host-only callers (`CloudflareController`) pass `['LARAKUBE_EXECUTION_CONTEXT' => 'host']`.
- `desktop/app/Http/Controllers/CloudflareController.php`: Strip `\0` NUL bytes, pass `['LARAKUBE_EXECUTION_CONTEXT' => 'host']`, and surface actionable CLI/WSL error details.
- `desktop/app/Services/SshKeyInspector.php`: Fall back to `ToolLocator::hostProfile()` when `HOME` is empty.
- Add tests in `desktop/tests/Feature/CloudflareTest.php`.

### Step 5: Full Verification (Sequential, Zero Regressions)
- Run `composer format`, `composer analyse`, and full `composer test` in `cli/`.
- Run `npx vp check --fix && npx tsc --noEmit` and full `composer test` in `desktop/`.
