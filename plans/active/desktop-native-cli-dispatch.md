# Desktop: stop wrapping `larakube` in `wsl.exe` — let the CLI decide

**Status:** Design only, not started. Saved so work can resume cold.
Read this whole file before touching code — the scope boundary section
matters as much as the design itself.

## Context

LaraKube Desktop was meant to be a thin expression of the CLI (ADR 0001:
"Drive the LaraKube CLI as a subprocess; never embed its code") — but on
Windows, Desktop ended up owning a piece of knowledge that really belongs
to the CLI: *when does a command need to run inside WSL*. That logic has
lived in `desktop/app/Services/LaraKube/ToolLocator.php` since Desktop's
Windows support was first built — every `larakube` invocation gets wrapped
in `wsl.exe -d larakube-ubuntu --user larakube --exec env ...` before it
ever runs, unconditionally, because the CLI itself had no way to make that
call on its own.

That's no longer true. A separate, already-in-progress change in the `cli`
repo (`app/Services/WslBridge.php`, wired into the `larakube` entry point
script itself) gives the CLI exactly this self-sufficiency: run natively on
Windows, and the CLI's own bootstrap decides per-command whether to handle
it locally or transparently re-exec itself inside WSL
(`wsl.exe -d larakube-ubuntu -- larakube <same args>`). Confirmed by
reading the actual code (not assumed): `WslBridge::shouldDispatchToWsl()`
dispatches *almost everything* — only `setup`, `update`, and `wsl:*`
commands stay native; everything else gets forwarded. This is the other
half of the fix: now that the CLI can decide for itself, **Desktop should
stop deciding for it.**

## Ground truth (verified by direct investigation, not assumed)

**`ToolLocator` is not a `larakube`-only wrapper.** It's Desktop's single
generic "find and isolate any CLI tool" utility, and on Windows it
currently routes *every* tool it resolves through the same
`wsl.exe`-wrapping (`isolate()` → `isolateForWsl()`), regardless of
whether that tool has anything to do with the LaraKube CLI:

- **`larakube` itself** — ~18 call sites across jobs and services, all
  going through `ToolLocator::find('larakube')` + `isolate()`/`run()`/
  `start()`. `CliRunner.php:61` hardcodes `'larakube'` as the binary name
  (not parameterized) — it's the primary caller, but far from the only one.
- **`kubectl`** — run **directly by Desktop, independent of the CLI
  entirely**, in at least 8 files (`ProjectController`, `SettingsController`,
  `CompanionController`, `LocalCluster`, `KubeconfigDiscovery`,
  `ClusterMetrics`, `ClusterStatus`, `ContextHealth`).
- **`ssh`** (`DevBoxShell.php`, for dev-box remote execution).
- **Editor CLIs** (`code`, `cursor`, `zed`, `phpstorm`, etc. —
  `EditorLauncher.php`).
- **Generic tool-presence checks** (`git`, `docker`, `podman`, `aws`,
  `gcloud`, `gh`, `tea`, `k9s`, `tofu`, `agy`, `claude`, `opencode` — via
  `ReadinessCheck.php` and a few settings-page checks in
  `GlobalSettings.php`).

**This means the fix is narrower than "redesign ToolLocator."** Only the
`larakube`-binary call sites should stop going through WSL-wrapping —
kubectl, ssh, editor CLIs, and the presence-check probes are a different
concern entirely and must keep working exactly as they do today.

**Project files live inside the WSL distro's filesystem today**
(`WslDistro::HOME.'/projects'` = `/home/larakube/projects`), not on the
native Windows filesystem. This looked like it might be a blocker, but
isn't one in practice: per `WslBridge::shouldDispatchToWsl()`, nearly every
command that actually touches project files (`up`, `tool:init`, etc.) gets
dispatched into WSL anyway — only `setup`/`update`/`wsl:*` stay native, and
none of those need project file access. So the commands that run natively
never need to reach into the distro's filesystem, and the commands that do
need it still run inside WSL exactly as today, operating on paths that are
already correct from inside the distro. **No project-path migration
needed as part of this plan** — flagging this explicitly since it was the
one finding that looked like a blocker at first read.

**Antigravity has already built the native-exe install path** (uncommitted,
`desktop/app/Services/LaraKube/CliInstaller.php`): on Windows, `binDir()`
now resolves to `<Windows USERPROFILE>\.larakube\bin\larakube.exe`,
downloaded via plain HTTP — the same mechanism already used for mac/linux,
no WSL involved. This plan **builds on top of that**, not in place of it —
don't re-do the install-path work, it already exists.
`ToolInstallController::updateCli()` currently does **both** the new
native-exe install **and** the old WSL-routed `larakube update` — a
transitional dual-path state. This plan's change is what lets that second,
WSL-routed call become redundant (see Design §3).

**`WslDistro.php` is load-bearing far beyond `ToolLocator`** — distro
lifecycle (`Wsl.php`: import/backup/restore/health-check), terminal
launching (`TerminalLauncher.php`), and path-translation for *display*
(`toWindows()`/`toLinux()`, used across `ProjectController`,
`DevBoxController`, `ClusterAccessController`, `RunController`, the
`Project` model). **None of that changes.** This plan only touches how a
`larakube` *process invocation* gets dispatched, not distro management or
path display.

## Design

### 1. `ToolLocator` gets native-exe awareness, scoped to `larakube` only

In `find()` and `isolate()`, add a narrow special case: when the binary
being resolved/run is `larakube` specifically, on Windows, *and* a native
`larakube.exe` exists at the location `CliInstaller` already installs it to
(`ToolLocator::hostProfile(true).'/.larakube/bin/larakube.exe'`) — resolve
to that native path and skip `isolateForWsl()`'s `wsl.exe` wrapping
entirely. Every other binary (`kubectl`, `ssh`, editor CLIs, the
`ReadinessCheck` probes) keeps today's behavior unchanged — `isWindows()`
still routes them through `isolateForWsl()` exactly as now.

This is deliberately a change in **one place** (`ToolLocator`), not a
rewrite of all ~18 `find('larakube')` call sites — they all go through the
same shared `find()`/`isolate()`/`run()`/`start()` methods, so fixing the
resolution logic there fixes all of them at once.

Fallback: if the native exe isn't found (not installed yet, or an
in-progress update), fall back to today's `findInDistro()` behavior rather
than failing outright — don't regress a working install while this lands.

### 2. Environment isolation needs its own native-Windows design — open question, not resolved here

Today's `isolate()` (non-Windows) and `isolateForWsl()` (WSL) both solve
the same problem: Desktop is itself a Laravel app, and naively inheriting
its own environment (`DB_CONNECTION`, `APP_CONFIG_CACHE`, etc.) into a
child `larakube` process breaks it. The non-Windows path does this via
`/usr/bin/env -i ASSIGNMENTS... "$@"`; the WSL path does the equivalent
through `wsl.exe --exec env ...`. **Neither mechanism exists on native
Windows** — there's no `env -i` equivalent, and Laravel's `Process::env()`
facade's inheritance behavior needs to be checked directly (does it merge
with the parent process's environment by default, or replace it?) before
assuming a native invocation is safe. This needs real investigation at
implementation time, not a guess baked into this plan — flagging it as the
one piece of real engineering risk in an otherwise mechanical change.

### 3. `ToolInstallController::updateCli()` simplifies once #1 lands

Once `ToolLocator` resolves `larakube` to the native exe on Windows, the
existing `CliRunner`-driven `larakube update ...` call (which today still
routes through WSL) would **also** hit the native binary automatically —
no separate code path needed. At that point the explicit
`$installer->install($channel)` call Antigravity already added becomes the
*only* update mechanism needed; the dual-path state can collapse back to a
single call. Confirm this is actually redundant (not just probably) before
removing anything — check whether `cli/app/Commands/UpdateCommand.php`'s
native-Windows branch (added alongside `WslBridge`) does anything
meaningfully different from the in-distro `update` command before assuming
the two converge.

## Explicitly out of scope

- Redesigning how `kubectl`, `ssh`, or editor CLIs get dispatched on
  Windows — they stay on today's `isolateForWsl()` path. Not part of the
  CLI-WSL decoupling question; changing them is unrelated scope creep.
- Moving default project storage off the WSL distro's filesystem — verified
  not needed (see Ground truth above).
- Any change to `WslDistro.php`, `Wsl.php` (distro lifecycle), or
  `TerminalLauncher.php` — all orthogonal, all stay as-is.
- Re-doing `CliInstaller.php`'s native-exe install path — already built by
  Antigravity, this plan assumes and builds on it.

## Critical files

- `desktop/app/Services/LaraKube/ToolLocator.php` — `find()`, `isolate()`
  (the one place the actual change lands).
- `desktop/app/Services/LaraKube/CliInstaller.php` — read-only reference
  for where the native exe already gets installed (`binDir()`).
- `desktop/app/Http/Controllers/ToolInstallController.php` — `updateCli()`,
  for the eventual dual-path simplification (§3), only after confirming
  it's safe.
- `cli/app/Services/WslBridge.php`, `cli/larakube` (entry point) —
  read-only reference for the CLI-side mechanism this plan assumes exists;
  don't modify, that's Antigravity's work.

## Verification

- `composer test` (desktop) — existing `ToolLocator`/`CliRunner` test
  coverage must keep passing unchanged for every non-`larakube` binary
  (kubectl, ssh, editor CLIs) on Windows; add new cases specifically for
  `find('larakube')`/`isolate([...'larakube'...])` resolving to the native
  exe path and skipping `wsl.exe` wrapping, plus the fallback-to-distro
  case when the native exe isn't present yet.
- Manual, on a real Windows machine: confirm a Desktop-driven run (e.g.
  "Set up local development," a tool deploy) no longer shells through
  `wsl.exe` for the `larakube` invocation itself (visible in Task
  Manager/Process Explorer as a direct `larakube.exe` child process, not
  `wsl.exe` wrapping it), while kubectl-backed pages (Settings' context
  picker, cluster metrics) are visibly unaffected.
- Confirm no secret leaks into the native subprocess's environment or argv
  — this is the one place §2's isolation redesign could silently regress
  something that used to be safe.
