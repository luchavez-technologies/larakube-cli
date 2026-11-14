# Windows host vs WSL: tool ownership, image dedupe, local-cluster reachability

## Context

Antigravity's commits (desktop `34b9bbe`, `eefa66c`, `e705a5c`; cli `fb388bb3`) tried to stop the CLI tools (kubectl, tofu, gcloud…) from behaving unpredictably on Windows. Each tool can live on the Windows host, inside the `larakube-ubuntu` distro, or both.

**What it got right**
- CLI `WslBridge` now uses a WSL allowlist (local-dev commands only) instead of a native allowlist. Cloud and tool commands now stay on the host by default. This is the right direction.
- It removed the `2>/dev/null` and `'jsonpath'` constructs that broke under `cmd.exe`.
- Desktop gets a per-tool class per binary (`desktop/app/Services/LaraKube/Tools/*`) behind `CliToolContract`.

**What is fickle or unfinished**
1. **Two sources of truth in Desktop.** `ReadinessCheck::TOOLS` is still alive and is still read at lines ~150, 219 and 243. `ToolLocator::WINDOWS_NATIVE_TOOLS` duplicates `runsOnWindowsHost()`, and `isWindowsNativeTool()` is now dead code.
2. **The registry only drives the Setup screen.** About 40 runtime call sites (`ClusterStatus`, `ProjectPods`, `TunnelManager`, `CliRunner`…) still call `$locator->find('kubectl')` directly. Setup and runtime can therefore disagree about which binary is used.
3. **A third copy of path knowledge.** Desktop's `ToolLocator::nativeWindowsToolPath()` re-implements the CLI's `CliTool::candidatePaths()`/`resolveBinary()` (1,517-line enum). That breaks ADR 0001: drive the CLI, don't re-embed it.
4. **A fat contract.** `CliToolContract::dispatchInstall()` takes `CliRunner`, `CliInstaller` and `ReadinessCheck`. `PodmanTool` and `DnsmasqTool` read `PHP_OS_FAMILY` directly instead of the injected locator, so they can't be tested.
5. **Two kubeconfigs.** `cluster:setup` merges the k3s context only into WSL's `~/.kube/config`, and cloud contexts land only in the Windows one. Desktop's native `kubectl.exe` cannot see the local cluster, and the two files drift apart.
6. **The image duplicates host tools.** `larakube-ubuntu` bakes in tofu, aws, gcloud, tea and k9s, which are now host-only concerns.
7. **The WSL allowlist is unguarded.** A new local command that someone forgets to add silently runs on the host. The comment still says "k3d" (the local cluster is k3s).

**Answer to "can kubectl outside WSL talk to k3s inside?"** Yes. k3s listens on `*:6443`, WSL2 forwards it to Windows `127.0.0.1` (both NAT and mirrored mode), and k3s's certificate already includes `127.0.0.1` as a valid name. There are only two gaps:
- (a) the Windows kubeconfig doesn't have the context;
- (b) WSL can stop the distro when it's idle, and then `127.0.0.1:6443` refuses connections. Desktop has to wake the distro first.

**Precedence rule (resolves the "kubectl in both" confusion):** each tool has one owning context. Desktop never runs a WSL copy of a tool that also exists on the host. The WSL copies of kubectl, git, gh and podman exist only so WSL-side larakube can work. They are image-managed, and Setup does not show them as separately installable. LaraKube CLI is the only tool with a first-class presence in both contexts.

---

## Step 0: audit trail
- Copy this plan to `cli/plans/active/windows-host-wsl-tool-ownership.md` (AGENTS.md plan-location rule).

## Phase 1: clean up Antigravity's leftovers (small, low-risk; can ship alone)
- `desktop/app/Services/LaraKube/ReadinessCheck.php`: delete `TOOLS`, and read `versionArgs`/keys from `CliToolRegistry` everywhere.
- `desktop/app/Services/LaraKube/ToolLocator.php`: delete `isWindowsNativeTool()`. Derive the native list from the registry (`runsOnWindowsHost()`) instead of `WINDOWS_NATIVE_TOOLS`.
- `PodmanTool` / `DnsmasqTool`: use `$locator->isWindows()` / `isDarwin()`, not `PHP_OS_FAMILY`.
- `CloudflareController`: drop the ad hoc `LARAKUBE_EXECUTION_CONTEXT=host`. It's redundant now that `dns:verify` is host-side.
- CLI `WslBridge`: fix the "k3d" comment. Add a test (`tests/Unit/WslBridgeTest.php`) that enumerates every registered command. It should assert that each one is either in `WSL_LOCAL_COMMANDS`/`WSL_LOCAL_ENV_COMMANDS` or in an explicit `HOST_COMMANDS` list, so an unclassified command fails CI.

## Phase 2: one kubeconfig, owned by Windows, shared with WSL
Two kubectl binaries (host and WSL) read **one** file: `%USERPROFILE%\.kube\config`. There is no second kubeconfig and no merging between files.

- **Point WSL at the host file.**
  - CLI `WslBridge::dispatchToWsl()`: pass `KUBECONFIG=<host profile>\.kube\config` with `WSLENV=KUBECONFIG/p`. WSL translates it to `/mnt/c/Users/<u>/.kube/config` itself, with no `cmd.exe` call.
  - Before dispatching, create `%USERPROFILE%\.kube\` if it's missing.
  - `larakube-ubuntu`: add `/etc/profile.d/larakube-kubeconfig.sh`. It derives the same path via `wslpath "$(/mnt/c/Windows/System32/cmd.exe /c 'echo %USERPROFILE%' 2>/dev/null)"`, so an interactive `wsl` shell and k9s agree.
  - The dispatch env var is authoritative. The profile script is only a convenience for interactive shells.
- **`cluster:setup` writes straight into the shared file.**
  - `ClusterSetupCommand::mergeKubeconfig()` (~L312-390) already targets "the user's kubeconfig". Make it honor `$KUBECONFIG` when set, and default to `~/.kube/config` otherwise, so macOS and Linux are unchanged.
  - Server: `https://127.0.0.1:6443`. That works inside WSL (k3s itself) and on Windows (WSL2 localhost forwarding). k3s's certificate already accepts `127.0.0.1`.
  - Always write with `kubectl config view --flatten`, so certs are embedded. A cert path like `C:\…` or `/etc/rancher/…` would break on the other side.
  - Apply the same `$KUBECONFIG` rule to `cluster:destroy`, `ContextRemoveCommand`, and the context-rename hook mentioned at ~L392.
- **Credential plugins.** GKE and EKS contexts call `gke-gcloud-auth-plugin` or `aws`, which live only on the host after Phase 4. kubectl only runs a plugin for the context in use, and WSL-side larakube only uses the local context. Add one assertion: commands that `WslBridge` routes to WSL never pass a non-local `--context` (already covered by `hasNonLocalContextFlag()`, so add a test).
- **Wake the distro.** Desktop: add `Wsl::ensureDistroRunning()`, which runs `wsl.exe -d larakube-ubuntu --exec /bin/true` (systemd then starts k3s).
  - Call it before local-context kubectl calls on Windows (`ClusterStatus`, `ProjectPods`, `LocalCluster`).
  - Gate it on the context being the local one.
- **No migration code** (per the no-one-time-migration rule). An existing WSL `~/.kube/config` is simply no longer read. Re-running `cluster:setup` (it's idempotent) registers the local context in the shared file.

## Phase 3: the CLI owns tool knowledge; Desktop asks
- Split `cli/app/Enums/CliTool.php` into `cli/app/Tools/{Kubectl,K9s,Tofu,Gcloud,Aws,Hcloud,Gh,Tea}Tool.php`. Follow the ClusterTool vendor-decomposition precedent.
  - Small contracts: `ResolvesBinary`, `InstallsOnUnix`, `InstallsOnWindows`, `Updatable`, `Authenticates`.
  - A shared trait: `ResolvesFromCandidatePaths`.
  - Each class declares its context: `HostOnly` or `HostAndWsl`.
  - The enum stays as a thin registry so existing `CliTool::KUBECTL->…` callers keep working.
- New standalone command `larakube tools:status --json` (not a hidden flag on `setup`). It returns, per tool:
  - `{slug, context: host|wsl, installed, path, version, installable, updatable, bundled}`
  - On Windows, the host process reports host tools and reads WSL-bundled versions from `/etc/larakube-rootfs.json` with one `wsl.exe` call.
- Desktop:
  - `ReadinessCheck::status()`/`catalog()` consume `tools:status --json`, cached per ADR 0004/0012.
  - `Tools/*` shrink to UI copy only: label, purpose, required, provider.
  - `ToolLocator` keeps locating only `larakube` (host + WSL) plus process isolation.
  - `ToolLocator::find('kubectl'|'k9s'|'ssh'|…)` resolves from the cached status, so all ~40 call sites follow the same answer without editing each one.
  - Install/update stays `setup --tools=…` via `CliRunner`, so `dispatchInstall()` no longer needs `CliInstaller`/`ReadinessCheck` except for the larakube binaries themselves.

## Phase 4: dedupe the `larakube-ubuntu` image
- `larakube-ubuntu/Dockerfile`:
  - Remove OpenTofu, the Google Cloud SDK and its GKE plugin, AWS CLI v2, tea and k9s.
  - Keep the CLI, kubectl, k3s, git, openssh-client, podman and gh.
  - Trim `/etc/larakube-rootfs.json`, `scripts/smoke.sh`, `README.md` and the header comment to match.
- Desktop Setup: the WSL section shows `larakube-wsl` plus a read-only "Bundled in larakube-ubuntu" list (kubectl, git, gh, podman with versions from `tools:status`). None of them are installable.

## Deferred (needs its own investigation, not in this plan)
`~/.larakube` is also split between host and WSL (server registry, global settings, local CA). Before deciding to share it or sync it, list which files WSL-local commands (`up local`, `new`, `trust`) actually read. Note that `~/.ssh` must never be shared over drvfs, because the permissions there break private keys.

## Verification
- CLI: run the targeted Pest files during the work, then the full `composer test` (zero failures) before declaring done. Run one heavy process at a time.
- Desktop: `php artisan test --compact` for the Readiness/CliInstaller/UpdateCli/ToolLocator tests, then the full suite. Run `native:migrate` only if a migration appears (none expected).
- The user runs `./build`. Battle test on Windows:
  - `larakube cluster:setup`, then from PowerShell `kubectl.exe --context <local> get nodes`. Run `wsl -d larakube-ubuntu kubectl config get-contexts` and confirm it lists the same contexts, cloud ones included.
  - `wsl --terminate larakube-ubuntu`, then the Desktop local-cluster page recovers on its own.
  - `larakube tools:status --json` on Windows and macOS.
  - The Setup screen shows no duplicate kubectl.
- Image: run `scripts/build.sh` then `scripts/smoke.sh`, re-import the distro, and confirm `larakube up` local still builds and deploys.
- Each phase is committed separately (conventional commits). Phases 1-2 are `fix(...)`, Phase 3 is `feat(tools)` + `refactor`, and Phase 4 is `feat!`-free `chore(image)`.

---

## Implementation notes (Phases 1, 2 and the image half of 4 done)

Where the build differs from the plan above, and why:

- **Shared kubeconfig is a symlink, not `KUBECONFIG`.** The CLI hardcodes `home_path('.kube/config')` in ~10 places and `Kubectl` sets `KUBECONFIG` itself, so an env var from the host would be overridden. `WslBridge::dispatchToWsl()` instead passes `LARAKUBE_HOST_KUBE_DIR=/mnt/<drive>/…/.kube` (and creates the host folder), and the entrypoint calls `WslBridge::linkHostKubeDirectory()` to keep `~/.kube` a link to it. A real `~/.kube` (kubectl makes one for its cache) is moved to `~/.kube.distro`, never deleted. No `/etc/profile.d` script and no `ClusterSetupCommand` change were needed.
- **The WSL routing guard has no `HOST_COMMANDS` list.** With 427 commands, that list would rot. Instead `WslBridgeTest` asserts that every routed name is a registered command, and that every `companion:`/`feature:`/`share:`/`trust:`/`*:new` command (except the `cloud:new` alias) is routed. It found: phantom `artisan`, `tinker`, `laravel:new`, `workspace:start`/`stop` and `status`; `workspace:*` wrongly sent to WSL (those are remote dev pods, so they belong on the host); and 10 scaffolders, 2 aliases and `trust:remove`/`reset` missing.
- **`CloudflareController` keeps `LARAKUBE_EXECUTION_CONTEXT=host`** to guard against an older paired CLI that still routes `dns:verify` into WSL. The token also moved out of argv into `LARAKUBE_CLOUDFLARE_TOKEN` (ADR 0002).
- **Waking the distro is narrower than planned.** `LocalCluster` probes `k3s-larakube` and, on Windows, only calls `Wsl::ensureDistroRunning()` and retries when that probe fails. Other local kubectl callers (`ProjectPods`, `ClusterStatus`) don't wake it yet.
- **`ToolLocator::WINDOWS_NATIVE_TOOLS` stays until Phase 3.** A test pins it to the registry's `runsOnWindowsHost()` set.

Still to do: Phase 3 (the CLI owns tool knowledge, plus `tools:status --json`) and the Desktop half of Phase 4 (a read-only "Bundled in larakube-ubuntu" list).
