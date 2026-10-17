# Let LaraKube manage WSL's own lifecycle from outside it

**Status:** Design only, not started. Saved so work can resume cold —
whoever picks this up next (human or another agent) should be able to work
from this file alone, without needing prior conversation context.

**Size unknown.** Phase 1 is small (a few files, same-day sized). Phase 2 is
a real feature with an unverified build step — budget discovery time, don't
assume it's quick.

**Related but distinct plans already in this directory** — read this file
first, but these are relevant background, not duplicates:
- `wsl-hosts-networking.md` — mirrored networking + Windows hosts sync
  itself (code-complete per that file's own status line). This plan assumes
  that work exists and is about a *different* problem: once mirrored
  networking is configured, nothing can activate it without the user
  manually running `wsl --shutdown`.
- `wsl-distro-improvements-and-local-dev.md` — broader Windows/WSL distro
  friction points (folder picker, non-destructive update). Overlaps in
  topic area (WSL on Windows) but covers different, unrelated issues.
- `wsl-dnsmasq-nrpt-resolution.md` — the mirrored-networking + NRPT DNS
  resolution feature itself. This plan is downstream of that one: it's
  about *activating* a config that feature already writes.
- `wsl-staleness-detection.md` — a separate, small, already-approved fix
  (restoring distro-staleness detection). Unrelated topic, just lives in
  the same directory.

## Context

**The problem, concretely**: LaraKube Desktop's "Set up local development"
flow on Windows writes WSL mirrored-networking config to `~/.wslconfig`,
then prints: *"WSL mirrored networking is configured in ~/.wslconfig but
not active yet... Run `wsl --shutdown` from Windows PowerShell, then reopen
your terminal."* The user has to leave the app, open PowerShell themselves,
run the command, and come back — even though LaraKube built its own custom
`larakube-ubuntu` WSL distro specifically to automate this class of thing.

**Why it's like this today (verified directly, not assumed)**: on Windows,
Desktop runs the CLI *inside* the `larakube-ubuntu` WSL distro (ADR 0007,
`desktop/docs/decisions/0007-windows-via-a-bundled-wsl-distro.md` —
every command runs as `wsl.exe -d larakube-ubuntu -- larakube ...`).
`wsl --shutdown` tears down the entire WSL2 VM, including the Linux kernel
hosting the CLI's own PHP process mid-command. A process cannot ask the
hypervisor to tear down the VM it is executing inside and then keep
running — it would be killed before finishing anything else. The code
already documents this:
`cli/app/Traits/ConfiguresWslNetworking.php:58-61` — *"Applying it needs a
one-time `wsl --shutdown`, surfaced as a reminder rather than run here:
shutting WSL down mid-setup would kill this very process."* This is a hard
constraint — nothing running *inside* WSL can ever restart WSL. Any fix has
to come from a process that lives *outside* WSL.

**A bigger alternative was considered and ruled out**: porting the CLI's
actual command logic to run natively on Windows (not just the entry point —
every operation) was already evaluated when ADR 0007 was written
(`cli/plans/active/larakube-desktop-feasibility.md:156-172`, "W2: native
Windows port" — rejected because the CLI's whole idiom is POSIX shell: 138
`sudo` calls, 73 `2>/dev/null`, 69 `~/` expansions, 34 `command -v` checks
at the time; re-confirmed current as of this plan: ~666
`Process::run()`/`Process::start()` calls scattered across dozens of
traits/commands with no single chokepoint to redirect, and the local
cluster is native `k3s` via `curl | sh`, hard-gated to Linux-only). **Do
not revisit that** — it would be a ground-up port, not a quick win, and
nothing in this plan requires it.

## What this plan actually proposes

Keep every real CLI operation dispatched into WSL exactly as it is today —
unchanged. Only change *what launches first* on Windows: give the CLI (and
Desktop) a way to manage WSL's *lifecycle* (enable, import, shutdown,
restart) from a process that lives outside the VM, so restarting WSL to
apply a config change doesn't require killing the very process asking for
the restart.

## Phase 1 — DONE (desktop `9a7ba2d`)

`LocalSetupController::store()` now restarts WSL automatically the first
time mirrored networking is freshly written to `~/.wslconfig`, but only
when no other WSL distro is currently running (checked via a new
`Wsl::otherRunningDistros()`/`distroIsRunning()`/`restartForNetworkingChange()`).
If another distro is running, the restart is skipped and the CLI's own
reminder remains the fallback — unchanged from before. 607/607 desktop
tests pass. See the original Phase 1 description below for the full
reasoning; kept for reference.

### Original Phase 1 description (small, do this first): wire up what Desktop already has

Desktop is *already* a native-Windows process sitting outside WSL, and it
already has the one capability needed here —
`Wsl::shutdown()` (`desktop/app/Services/Wsl.php:427-434`, runs `wsl.exe
--shutdown`). Today its only caller is window-close
(`desktop/app/Listeners/ShutdownWslOnWindowClose.php:47-51`), and only when
`GlobalSettings::wslShutdownMode()` is explicitly `'shutdown'` (default is
`'terminate'`, which is scoped to `larakube-ubuntu` only, via
`Wsl::terminate()`). The setup flow
(`desktop/app/Http/Controllers/LocalSetupController.php`,
`Wsl::ensureMirroredNetworking()` at `desktop/app/Services/Wsl.php:489-517`)
writes `.wslconfig` but never calls `shutdown()` to actually apply it —
that's the gap to close.

Steps:
1. **New safety check in `desktop/app/Services/Wsl.php`**: before offering
   a restart action, run `wsl --list --running` and check for any distro
   besides `larakube-ubuntu`. None running → the restart can fire
   `Wsl::shutdown()` immediately. Others running → name them in the UI and
   require an explicit "Shut down WSL anyway" confirmation — `wsl
   --shutdown` kills *every* distro on the machine, not just this one, and
   nothing in `Wsl.php` checks for that today (confirmed: no code
   enumerates other distros' running state anywhere in this file).
2. **Wire it into `LocalSetupController.php`**: the step that currently
   only prints the "configured but not active" reminder needs a real
   action next to the text, not just a message — reuse the existing
   "one-time action(s) needed" card pattern already in the setup UI.
3. **After shutdown**: wait briefly, then run `wsl -d larakube-ubuntu echo
   ok` (same pattern `Wsl::check()` already uses elsewhere in this file)
   to confirm the VM came back up with mirrored networking active, then let
   setup continue automatically. No "reopen your terminal" instruction is
   actually needed for Desktop users doing this — that instruction was
   always UX insurance for a *terminal's own* stale shell state, never a
   technical WSL requirement; the networking change is live as soon as the
   VM restarts.

This phase doesn't touch the CLI at all. CLI-only users (no Desktop
installed) keep today's manual instruction unchanged until Phase 2 ships.

**Critical files**: `desktop/app/Services/Wsl.php`,
`desktop/app/Http/Controllers/LocalSetupController.php`, Desktop's
setup-flow frontend (the "one-time action(s) needed" card).

**Verification**: `composer test` (desktop) — extend
`desktop/tests/Feature/WslTest.php` with "other distros running" cases
(fake `wsl --list --running` output with/without extras), confirm
`shutdown()` only fires when safe or explicitly confirmed. Manual: on a
real Windows machine with mirrored networking freshly configured but
inactive, confirm the restart action appears, correctly names another
running distro when present, and setup continues on its own after a clean
restart with no manual terminal reopen.

## Phase 2 (bigger, do this after Phase 1 ships and is verified): give the CLI itself a native-Windows lifecycle layer

This generalizes Phase 1's fix to CLI-only users (no Desktop), by giving
the `larakube` CLI itself a native Windows build that can manage WSL from
outside it, the same way Desktop already can.

**New finding, not previously known**: a native Windows `.exe` of the CLI
does not exist today. `cli/build`'s `--all` target (`cli/build:109-118`)
only builds `linux x64/arm` and `mac x64/arm` — Windows was never added,
even though the underlying tool (phpacker) supports it
(https://laravel-zero.com/docs/distribute-as-a-single-executable-binary).
This needs a build target exercised for the first time on this project —
budget time for unknowns in phpacker's Windows cross-compile path, don't
assume it "just works" on the first try.

**Updating the native `.exe` is not a new problem to solve**: checked
directly — `larakube-ubuntu`'s own image rebuild (CI-triggered, keeps the
base system fresh) is entirely separate from how the CLI binary itself
updates. `larakube update` (`cli/app/Commands/UpdateCommand.php:120-187`)
already does a simple re-download-and-atomic-swap of the release asset, no
distro rebuild involved — and `CliInstaller.php`'s mac/linux flow
(`desktop/app/Services/LaraKube/CliInstaller.php:81-146`) already does the
identical thing *outside* WSL today. A native-Windows `.exe` just needs a
`larakube-windows-x64.exe` release asset (produced by step 1 below) and one
more OS branch in `ToolInstallController::updateCli()` doing the same
download-and-swap already proven for mac/linux. No new update mechanism to
invent.

Steps:
1. Add a Windows target to `cli/build`'s `--all` matrix
   (`build_standalone windows x64`, following the exact same pattern as the
   existing `linux`/`mac` calls at `cli/build:111-114`). Confirm phpacker
   actually produces a runnable `.exe`. This also produces the release
   asset Desktop's updater will need.
2. In the CLI, detect "running natively on Windows, not inside WSL" — both
   primitives already exist: `isWindows()`
   (`cli/app/Traits/InteractsWithOs.php`) and `isWsl()`
   (`cli/app/Traits/DetectsWsl.php:21-28` — note `isWsl()` can currently
   only ever be true when already on Linux, so `isWindows() && !isWsl()` is
   a new, not-yet-used combination worth checking doesn't trip any existing
   assumption elsewhere that conflates "is Windows" with "is inside WSL on
   Windows"). For WSL-lifecycle commands (`setup`, `trust`, `hosts`, and
   specifically the mirrored-networking restart from this plan), give the
   native `.exe` a direct path: manage WSL itself via `wsl.exe` subprocess
   calls (same shape as Phase 1's Desktop code — worth sharing
   logic/structure rather than writing a third implementation of the same
   thing), and dispatch everything else into WSL via
   `wsl.exe -d larakube-ubuntu -- larakube <same command>`, the same way
   Desktop's `CliRunner` already does today.
3. Add the Windows branch to `ToolInstallController::updateCli()`
   (`desktop/app/Http/Controllers/ToolInstallController.php:108-128`),
   mirroring the existing non-Windows branch (`CliInstaller::install()`),
   so the native `.exe` updates the same way mac/linux already do.
4. Optional follow-up once Phase 2 is proven: Desktop could stop
   duplicating `Wsl.php`'s lifecycle logic and shell out to the CLI's own
   native WSL-lifecycle commands instead, so there's one implementation
   instead of two. Not required for Phase 2 itself to ship.

**Critical files**: `cli/build` (new Windows target), `cli/app/Traits/DetectsWsl.php`
+ `cli/app/Traits/InteractsWithOs.php` (the `isWindows() && !isWsl()`
branch point), a new WSL-lifecycle command/trait in the CLI mirroring
`desktop/app/Services/Wsl.php`'s shape,
`desktop/app/Http/Controllers/ToolInstallController.php` (new Windows
update branch).

**Verification**: confirm `./build --all` produces a runnable
`larakube.exe` on a real Windows machine, outside any WSL distro. Confirm
`larakube setup` run from that native `.exe` can enable/import/restart WSL
by itself. Confirm a Linux-dependent command (e.g. `larakube trust`) still
correctly dispatches into WSL from the native `.exe` and produces identical
output to running it from inside the distro directly. Confirm
`updateCli()`'s new Windows branch correctly swaps the native `.exe` in
place.

## Explicitly out of scope (both phases)

- Porting any of the ~666 Linux-dependent `Process` calls to Windows-native
  semantics. They stay exactly as they are, always executed inside WSL.
  Do not revisit the rejected "W2: native Windows port" idea from
  `larakube-desktop-feasibility.md` — this plan is not that.
- Auto-detecting *what* another running WSL distro is doing before
  shutting it down — name it and ask the user, never try to distinguish
  "idle" from "mid-build" from outside.
- `wslShutdownMode` / window-close behavior in Desktop — unrelated to this
  plan, not touched.
