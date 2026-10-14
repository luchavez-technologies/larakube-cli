# Remove Containerized `gh`/`tea` and Bake Developer Tools in `larakube-ubuntu`

## Context
Originally, the LaraKube CLI ran `gh` (GitHub CLI) and `tea` (Tea CLI) inside temporary Docker/Podman containers as a zero-host-dependency fallback when the binaries were missing on the host machine.
However:
1. It requires an active container runtime even when managing remote cloud clusters.
2. It introduces latency, volume permission complexities, and authentication friction (inability to do interactive browser-based OAuth logins cleanly).
3. For Windows users, LaraKube Desktop provides a dedicated WSL distribution (`larakube-ubuntu`). Baking `gh`, `tea`, `aws`, and `gcloud` directly into the image ensures students and workshop attendees have all necessary cloud and forge CLIs pre-installed and ready out of the box with zero setup friction.

---

## Changes

### 1. `larakube-ubuntu` WSL Distro Image
- **Google Cloud SDK (`gcloud`) & GKE Auth Plugin**:
  - Install via Google's official APT repository (`google-cloud-cli`, `google-cloud-cli-gke-gcloud-auth-plugin`).
- **AWS CLI v2 (`aws`)**:
  - Install via official AWS CLI v2 standalone installer (`awscli-exe-linux-x86_64.zip` / `aarch64.zip`).
- **Tea CLI (`tea`)**:
  - Download official latest binary from Gitea (`tea-<version>-linux-<arch>`) to `/usr/local/bin/tea`.
- **System metadata & smoke tests**:
  - Update `/etc/larakube-rootfs.json` to record versions of `tea`, `aws`, `gcloud`.
  - Update `scripts/smoke.sh` to verify `tea`, `aws`, `gcloud`, and `gke-gcloud-auth-plugin`.
  - Update `README.md` and `release.yml`.

### 2. `cli` Engine
- **`InteractsWithGlobalConfig`**:
  - Remove `getGhDockerCommand()`.
  - Simplify `getGhCommand()` to `CliTool::GH->resolveBinary() ?? 'gh'`.
  - Simplify `getTeaCommand()` to `CliTool::TEA->resolveBinary() ?? 'tea'`.
- **Command & CI Guards**:
  - In `ConfiguresCloudEnvironment::configureGha()`, check `CliTool::GH->isInstalled()`.
  - In `ConfiguresCloudEnvironment::configureForgejo()`, check `CliTool::TEA->isInstalled()`.
  - In `GhaLoginCommand` and `GhCommand`, check `CliTool::GH->isInstalled()`.
  - In `GitLoginCommand`, check `CliTool::TEA->isInstalled()`.
- **Tests**:
  - Update `InteractsWithGlobalConfigProcessTest` and `GitLoginCommandTest` to reflect direct binary resolution instead of container execution.

### 3. `desktop`
- Add `gh` (GitHub CLI) and `tea` (Tea CLI) to `ReadinessCheck::TOOLS` as optional installable developer tools.
- Add `gh` and `tea` brand icons to `ProviderLogo` (`SiGithub`, `SiGitea`).
