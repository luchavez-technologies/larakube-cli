# Architecture Plan: LaraKube Clone UI & DevBox Git Provisioning

**Date:** 2026-10-06  
**Status:** Ready for Review  
**Author:** Antigravity  
**Target Repositories:** `cli/` and `desktop/`  

---

## 1. Executive Summary

This plan addresses two user questions:
1. **Does the DevBox have Git in case the user wants to clone their app into it?**
2. **Should we finally add the `CloneCommand.php` (`cli/app/Commands/CloneCommand.php`) UI to LaraKube Desktop, and where is the best place for it?**

We perform an audit of DevBox provisioning scripts, identify the exact state of Git on remote boxes, formulate solutions for Git authentication on headless VPS instances, and present a UI/UX architecture for integrating repository cloning across local machines and remote DevBoxes.

---

## 2. DevBox Git Availability Audit

### 2.1 Current State Analysis
When a DevBox is created via `larakube devbox:create` (or through the Desktop DevBox wizard), the host provisioning pipeline executes the following sequence in [`ProvisionsDevBox.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/ProvisionsDevBox.php):
1. `hardenServer()`: Installs `ufw`, `fail2ban`, `unattended-upgrades`, configures firewall rules.
2. `createLaraKubeUser()`: Creates the `larakube` sudo user with SSH key authentication.
3. `installCliOnBox()`: Downloads and installs the LaraKube CLI binary (`install.sh`).
4. `setUpLocalStackOnBox()`: Executes `larakube setup --profile=local --runtime=podman --no-interaction`.
5. [`SetupCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/SetupCommand.php) installs:
   - Rootless Podman dependencies (`podman`, `slirp4netns`, `fuse-overlayfs`, `uidmap`).
   - Local K3s cluster & Traefik ingress.
   - Developer CLI tools: `kubectl`, `k9s`, `tofu`.

### 2.2 Finding & Risk
- **`git` is NOT explicitly installed anywhere** in `ProvisionsDevBox` or `SetupCommand`.
- Standard cloud images (e.g., standard DigitalOcean Ubuntu 24.04 droplet images) often happen to include `git` in standard package groups, but **minimal cloud images** (such as `ubuntu-minimal`, custom provider VPS images, Hetzner minimal base, or Debian) do **NOT** guarantee `git` is present.
- If a user runs `larakube clone` on a DevBox without Git, it fails with `git: command not found`.

### 2.3 Hardening Requirement: Guaranteed Git on DevBox
We must make Git an **explicit, guaranteed dependency** during DevBox provisioning:
1. In `cli/app/Traits/ProvisionsDevBox.php`:
   Include `git` in the host package installation step:
   ```bash
   DEBIAN_FRONTEND=noninteractive apt-get update -y && apt-get install -y git
   ```
2. In `cli/app/Traits/ClonesRepositories.php`:
   Add a pre-flight check in `runGitClone()`:
   ```php
   if (trim(Process::run('command -v git')->output()) === '') {
       throw new RuntimeException('Git is not installed on this system. Please install git and retry.');
   }
   ```

---

## 3. Remote Git Authentication on DevBoxes

Cloning on a remote DevBox differs from local cloning when handling private repositories:

| Scenario | Local ("This computer") | Remote DevBox |
| :--- | :--- | :--- |
| **Public Repositories** (`https://github.com/org/repo`) | Works out-of-the-box via HTTPS. | Works out-of-the-box via HTTPS. |
| **Private Repositories (SSH)** (`git@github.com:org/repo.git`) | Uses local user's SSH keys (`~/.ssh/id_*`) and SSH agent. | DevBox does **not** have the user's private key. Fails unless **SSH Agent Forwarding** (`ssh -A`) is enabled. |
| **Private Repositories (HTTPS)** (`https://github.com/org/repo`) | Git Credential Manager / macOS Keychain / Windows Credential Store prompts or handles token. | Headless VPS cannot open a browser auth dialog. Requires Personal Access Token (PAT) or deploy token. |

### Recommended Solution for DevBox Private Repos:
1. **Support HTTPS with Personal Access Token (PAT)**:
   In the Desktop Clone dialog, provide an optional field:
   `Git Access Token (Optional, for private repositories)`:
   If provided, Desktop securely passes it to standard input (using `DevBoxShell::feeding()`), constructing the authenticated clone URL in memory without writing the token to shell arguments or logs.
2. **Support SSH Agent Forwarding (`-A`)**:
   Add `-A` support in `DevBoxShell` when configured, allowing `git@github.com:...` to leverage the host's existing SSH agent.
3. **Provider CLI Integration**:
   LaraKube already has `gh` (GitHub CLI) in `CliTool::GH`. If the user has authenticated `gh` on the box (`gh auth login`), `git clone` authenticates automatically through `gh auth git-credential`.

---

## 4. Where is the Best Place for the Clone UI?

### 4.1 Evaluation of Candidates

```
┌────────────────────────────────────────────────────────────────────────┐
│ Option A: Tab / Mode in "New Project" Page (projects/create.tsx)       │
│                                                                        │
│  [ Sparkles: From Scratch / Template ]  [ GitBranch: Clone from Git ]  │
│  ┌─────────────────────────────────┬────────────────────────────────┐  │
│  │ Git URL / Shorthand             │ Where: [ Laptop | DevBox ]     │  │
│  │ Branch                          │ App / Folder Name              │  │
│  │ Auth Token (optional)           │ Parent Folder / Path           │  │
│  │                                 │ [ Clone & Setup Project ]      │  │
│  └─────────────────────────────────┴────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────┐
│ Option B: Dedicated Header Action + Modal on Projects Index            │
│                                                                        │
│  Projects                                                              │
│  [ Cards | Table ]  [ FolderPlus: Add folder ]  [ Git: Clone ]  [+ New] │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ Clone Repository Modal                                           │  │
│  │ Repository URL: [ https://github.com/owner/repo               ]  │  │
│  │ Location:       (●) This computer   (○) test-dev-box             │  │
│  │ Folder Name:    [ repo                                        ]  │  │
│  │ [Cancel]                                        [ Clone Project ]│  │
│  └──────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

#### Comparison Matrix

| Criteria | Option A: Inside `projects/create` | Option B: Modal on `projects/index` | Option C: Unified Hybrid (Recommended) |
| :--- | :--- | :--- | :--- |
| **Mental Model** | "Creating a new project from an existing repo" fits conceptually alongside templates. | Quick action on the project list; immediate access. | Best of both: 1-click modal from index AND accessible inside create. |
| **DevBox Context** | User has to click "New project", then select DevBox radio, then select Clone tab. | **Context-Aware:** If user is looking at `test-dev-box`, clicking Clone defaults to `test-dev-box`! | Index button opens Clone Modal pre-filled with the active DevBox! |
| **Resolves DevBox Gap** | Yes. | **Huge win:** Currently DevBox has NO "Add existing folder" button; Clone fills this exact void. | Solves the DevBox gap completely on index and in create. |
| **Layout & Streaming** | Reuses `create.tsx` layout and `LogPanel`. | Modal can launch run and open slide-over / redirect to run stream. | Seamless experience across both views. |

---

### 4.2 Recommendation: The Unified Hybrid Architecture

1. **On `projects/index.tsx` (Top Actions Bar)**:
   - For **"This computer"**:
     `[ Cards | Table ]` `[ FolderPlus: Add existing folder ]` `[ GitBranch: Clone repo ]` `[ Plus: New project ]`
   - For **DevBoxes** (e.g. `?box=test-dev-box`):
     *(Note: "Add existing folder" is hidden because local folder picker cannot pick remote folders)*
     `[ Cards | Table ]` `[ GitBranch: Clone repo to dev box ]` `[ Plus: New project ]`
   - Clicking `[ GitBranch: Clone repo ]` opens a sleek **Clone Repository Modal**.

2. **In `projects/create.tsx` ("New Project" Page)**:
   - Add a subtle top banner or secondary toggle:
     `"Already have a repo on GitHub or GitLab? [Clone existing repository]"`
   - Clicking it toggles the view into Clone mode or opens the clone flow, ensuring users who clicked "New project" by habit don't feel lost.

3. **In the Clone Modal / Dialog**:
   - **Repository**: Text input with placeholder `owner/repo or https://github.com/...`
   - **Where**: Toggle between `This computer` and registered ready DevBoxes (`test-dev-box`, etc.). Defaults to whatever tab was active on `projects/index`.
   - **Folder Name**: Auto-computed from the repository name as user types (e.g. `laravel/laravel` $\rightarrow$ `laravel`), fully editable.
   - **Branch** (collapsible/optional): Specific branch to checkout.
   - **Token** (collapsible/optional): For private repos without SSH access.
   - **Buttons**:
     - `<Button variant="ghost">Cancel</Button>` (with `XCircle` icon)
     - `<Button variant="primary">Clone & Setup</Button>` (with `GitBranch` icon)

---

## 5. End-to-End Workflow & Backend Execution

```mermaid
sequenceDiagram
    autonumber
    actor User as Developer
    participant UI as LaraKube Desktop (React/Inertia)
    participant PC as ProjectController
    participant CR as CliRunner
    participant CLI as LaraKube CLI / SSH

    User->>UI: Clicks "Clone repository" on Projects page
    UI->>UI: Displays Clone Modal (defaults to active Location: Local vs DevBox)
    User->>UI: Enters "laravel/laravel", clicks "Clone & Setup"
    UI->>PC: POST /projects/clone or /projects/clone/dev-box
    alt Destination: "This computer"
        PC->>CR: start(kind: RunKind::CloneProject, args: ['clone', url, '--directory=' . dir])
        CR->>CLI: Runs local `larakube clone <url> --directory=<dir> --no-interaction`
    else Destination: DevBox
        PC->>CR: start(kind: RunKind::CloneDevBoxProject, devBox: box, args: ['clone', url, ...])
        CR->>CLI: SSH larakube@ip `cd ~/projects && larakube clone <url> --directory=<dir> --no-interaction`
    end
    CLI->>CLI: 1. git clone
    CLI->>CLI: 2. Detect framework (AppFramework::detect)
    CLI->>CLI: 3. Bootstrap .env with local/devbox APP_URL
    CLI->>CLI: 4. composer install / npm install
    CLI->>CLI: 5. larakube init
    CR-->>PC: Returns Run ($run->id)
    PC-->>UI: Redirects to Run streaming HUD / Activity view
    UI-->>User: Displays live log stream; on completion, project is ready!
```

---

## 6. Implementation Plan

### Phase 1: DevBox Git Hardening (`cli/`)
1. **Update `ProvisionsDevBox.php`**:
   - Ensure `git` is installed in `setUpLocalStackOnBox` or right before CLI setup:
     ```php
     $this->runRemoteCommand('root', $ip, $port, $keyPath, 'DEBIAN_FRONTEND=noninteractive apt-get update -y && apt-get install -y git');
     ```
2. **Update `ClonesRepositories.php`**:
   - Check if `git` is executable before calling `runGitClone()`.
   - Provide clear output if `git` is absent.
3. **Tests**:
   - Add unit/feature tests for `ClonesRepositories` ensuring missing git throws descriptive exception.

### Phase 2: Desktop Backend Routes & Runner (`desktop/`)
1. **Define Run Kinds in `RunKind.php`**:
   - `RunKind::CloneProject = 'clone-project'`
   - `RunKind::CloneDevBoxProject = 'clone-dev-box-project'`
2. **Add Endpoints in `ProjectController.php` & `routes/web.php`**:
   - `POST /projects/clone` (`cloneLocal`)
   - `POST /projects/clone/dev-box` (`cloneDevBox`)
3. **Feature Tests**:
   - Add tests in `desktop/tests/Feature/ProjectCloneTest.php` testing both local and DevBox clone dispatches.

### Phase 3: Desktop UI Implementation (`desktop/`)
1. **Clone Modal Component (`desktop/resources/js/components/clone-modal.tsx`)**:
   - Form state with repo URL, destination (local vs DevBox), folder name, branch, auth token.
   - Dynamic folder name extraction from repo URL.
   - Adherence to UI Button Icon standard (`GitBranch`, `FolderPlus`, `Plus`, `XCircle`).
2. **Update `desktop/resources/js/pages/projects/index.tsx`**:
   - Add `Clone repository` button to header actions with `GitBranch` icon.
   - Connect button to open `CloneModal`.
   - Pre-select current DevBox if active on `LocationSwitch`.
3. **Update `desktop/resources/js/pages/projects/create.tsx`**:
   - Add tab/link to open Clone flow for users arriving from "New project".

---

## 7. Verification & Testing Strategy

1. **CLI Tests**:
   - Run `composer format`, `composer analyse`, and `composer test` in `cli/`.
2. **Desktop Tests**:
   - Run `composer test` and `npm run check` in `desktop/`.
   - Test cloning a public repo locally in unit tests (mocking process runner).
   - Test DevBox SSH command formatting via `DevBoxShellTest`.
3. **Manual Verification**:
   - Test `larakube clone` with both HTTPS and shorthand (`owner/repo`).
   - Test Desktop Clone modal interaction on macOS and Windows WSL.
