# SSH Key Auto-Resolution & Deployment Resilience

**Target issue:** Remote deployment image sideload failure caused by hardcoded `~/.ssh/id_rsa` defaulting in `ResolvesEnvironmentContext::recordContextTarget()` and lack of SSH connectivity pre-flight checks.

---

## 1. Problem Statement

When linking a project to a remote server (e.g. via LaraKube Desktop's "Link Server" or `larakube env <name> --context=...`), `ResolvesEnvironmentContext::recordContextTarget()` prompts for or defaults SSH parameters (`user: larakube`, `port: 22`, `key: ~/.ssh/id_rsa`).

In non-interactive environments (Desktop / CI), Laravel Prompts silently accepts the default `~/.ssh/id_rsa` without inspecting:
1. Existing host configurations in `~/.ssh/config` (which `cloud:create` writes via `upsertSshConfigHost()`).
2. Global stack registrations in `~/.larakube/config.json`.
3. Explicit CLI flags on the `env` command (`--ssh-key=`, `--ssh-user=`, `--ssh-port=`).

Consequently, when deploying to VPS instances provisioned with a different key (such as `~/.ssh/do_personal` or `id_ed25519`), the image build runs for 1.5+ minutes before failing at the sideload step with:
`LARAKUBE Image sideload failed — check SSH access and passwordless sudo for 'k3s' on the host.`

---

## 2. Proposed Architecture & Solution

### A. Intelligent SSH Resolution (`ManagesSshKeys`)
- Introduce `resolveSshDetails(string $hostOrIp, ?string $context = null): ?array` in `ManagesSshKeys`.
- Resolves the identity key, user, port, and host alias by:
  1. Inspecting registered stacks in `GlobalConfigData` matching IP, name, or context.
  2. Parsing `~/.ssh/config` blocks matching `Host`, `HostName`, or stack alias.
  3. Expanding `~` to `home_path()` and verifying key existence on disk.
- Introduce `testSshConnection(string $user, string $ip, int $port, string $key, int $timeoutSeconds = 5): bool` using non-interactive `BatchMode=yes`.

### B. Command Flags & Smart Defaults (`EnvCommand` & `ResolvesEnvironmentContext`)
- Add `--ssh-key=`, `--ssh-user=`, `--ssh-port=` options to `EnvCommand`.
- In `recordContextTarget()`:
  - If `--ssh-key` is provided, use it.
  - Otherwise, resolve default SSH details using `resolveSshDetails()`.
  - Use the resolved key as the prompt/fallback default instead of hardcoded `~/.ssh/id_rsa`.
- In Desktop (`ProjectController::link()`):
  - Pass `--ssh-key` if available from the server model or catalog.

### C. Pre-Flight Verification & Self-Healing (`InteractsWithRemoteDeploy`)
- In `deployViaSshSideload()`:
  - Perform an SSH connectivity check **before** starting pre-deployment steps or Docker builds.
  - If `$cloud->key` fails authentication, automatically attempt self-healing using `resolveSshDetails()`.
  - If a working key is discovered and verified:
    - Update `.larakube.local.json` in place.
    - Log an informational message and proceed with the deployment.
  - If no working key connects:
    - Terminate immediately with a clear error message, saving developer time.

---

## 3. Implementation Steps

1. **`cli/app/Traits/ManagesSshKeys.php`**: Implement `resolveSshDetails` and `testSshConnection`.
2. **`cli/app/Commands/EnvCommand.php`**: Add `--ssh-key=`, `--ssh-user=`, `--ssh-port=` signature options.
3. **`cli/app/Traits/ResolvesEnvironmentContext.php`**: Use `ManagesSshKeys` and wire smart resolution into `recordContextTarget()`.
4. **`cli/app/Traits/InteractsWithRemoteDeploy.php`**: Add pre-flight SSH verification and self-healing logic to `deployViaSshSideload()`.
5. **`cli/app/Commands/Cloud/CloudStacksCommand.php`**: Include resolved `sshKey` in JSON output.
6. **`desktop/app/Http/Controllers/ProjectController.php`**: Pass `--ssh-key` when linking if present.
7. **Testing & Quality Assurance**:
   - Write unit and feature tests covering SSH resolution, headless env execution, and pre-flight validation.
   - Run `composer format`, `composer analyse`, and `composer test`.
