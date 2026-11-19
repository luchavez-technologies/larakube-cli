# Handoff: Windows / LaraKube Linux follow-ups

Status: done (committed locally, not pushed). Read AGENTS.md first. Desktop ADRs 0022 (naming) and 0021
(push updates), and `cli/plans/active/setup-tabs-and-shared-config.md`, give the context.
Commit only, never push. Run each repo's full test suite before every commit.

## Verification Rundown (Completed by Antigravity)
- **Item 1 (`desktop/develop` commit `df280ff` — `fix(wsl): keep LaraKube Linux asleep when listing Projects or Dashboard`)**:
  - Updated `app/Models/Project.php` (`existsOnHost(?bool $distroRunning)`), `app/Http/Controllers/ProjectController.php`, `app/Http/Controllers/DashboardController.php`, `app/Services/LaraKube/ProjectInspector.php`, `app/Services/LaraKube/ProjectWorkloadStatus.php`, and `app/Services/LaraKube/AppMenu.php` to check `Wsl::distroIsRunning()` once per request and use cached DB columns (`blueprint_cache`, `last_inspected_at`, etc.) without touching `\\wsl.localhost\larakube-ubuntu\...` when the distro is stopped.
  - Added tests in `tests/Feature/WakeLocalClusterTest.php` asserting zero filesystem/inspector wakeups on a stopped Windows WSL distro.
  - Verified with full `desktop/` suite (`vp check`, `tsc --noEmit`, `pint`, `phpstan`, `artisan test --parallel`).
- **Items 2 & 3 (`docs/main` commit `2ea7e98` — `docs(windows): adopt LaraKube Linux naming (ADR 0022) and document Windows WSL behavior`)**:
  - Updated `docs/desktop/windows.md` and `docs/onboarding/operating-systems/windows.md` to use "LaraKube Linux" in prose (keeping `larakube-ubuntu` only in code spans/commands), and documented the Wake up button / ~15s WSL idle sleep, Setup tabs (`This computer`, `LaraKube Linux`, `Also in…` badges), Windows command routing (`WslBridge.php` `HOST_COMMANDS` vs `WSL_*_COMMANDS`), and shared logins (`~/.kube`, `~/.aws`, gcloud config, `~/.larakube/config.json`, SSH keys).
- **Still untouched (as requested):** Real Windows machine verification and `git push`.

## 1. Projects page must not wake LaraKube Linux (Desktop)

On Windows, project paths live in the distro and are read through `\\wsl.localhost\larakube-ubuntu\…`.
Touching that path starts a stopped distro. That undoes WSL's idle stop, which the Wake up button
(commit `19e9776`) relies on.

Suspects:
- `Project::existsOnHost()` (`is_dir($this->hostPath())`)
- `ProjectInspector::inspect()`
- `ProjectController::index()`
- `DashboardController` (its `projects` and `stats` defer callbacks)
- any Sync*Job that reads project folders.

Wanted:
- On Windows, while `Wsl::distroIsRunning()` is false, these use the cached DB columns
  (`blueprint_cache`, `last_inspected_at`, etc.) and never touch `\\wsl.localhost`.
- Check `distroIsRunning()` once per request, not once per project.
- Tests: a Windows `Wsl` with a Stopped listing (see `tests/Feature/WakeLocalClusterTest.php`)
  must show no filesystem/inspector access, through an injectable seam if needed.

## 2. Docs site follows ADR 0022 (docs repo)

ADR 0022 sets the naming. User-facing prose says "LaraKube Linux". `larakube-ubuntu` appears only
in a code span, where users type or see it (`wsl -l`, `wsl --unregister`). The UI never names the
base image.

- Sweep the docs repo for "WSL distro", "larakube-ubuntu" in prose, "LaraKube Ubuntu" and "Ubuntu
  distro".
- Leave code blocks and commands alone.
- The docs dev server already runs on :3000. Do not build or serve it.

## 3. Docs pages for the new Windows behaviour (docs repo)

Short pages or sections, in plain words:

- **Wake up button:**
  - WSL stops an idle distro about 15 seconds after its last process exits.
  - Desktop shows "LaraKube Linux is asleep" on the Dashboard and Projects pages.
  - Wake up keeps the distro awake while Desktop is open.
  - A local up, start or heal does the same.
- **Setup tabs:**
  - "This computer" holds the installable Windows tools.
  - "LaraKube Linux" holds the read-only tools bundled with the image; updating LaraKube Linux
    updates them.
  - "Also in…" badges mark tools on both sides.
- **Where commands run on Windows:**
  - Project commands always run in LaraKube Linux, for every environment.
  - Server and Cluster Tool commands run on Windows.
  - Without LaraKube Linux, Windows is for servers and Cluster Tools only.
  - The source of truth is `cli/app/Services/WslBridge.php` (HOST_COMMANDS, WSL_*_COMMANDS).
- **Shared logins:**
  - `~/.kube`, `~/.aws`, the gcloud config and `~/.larakube/config.json` are shared from
    Windows into LaraKube Linux.
  - SSH keys made on Windows work from LaraKube Linux.

## Not for handoff

- Windows testing of all of the above. The user does this on a real Windows machine.
- Pushing. The user decides.
