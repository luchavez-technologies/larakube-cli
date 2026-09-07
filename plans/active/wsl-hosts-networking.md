# WSL Windows hosts sync + mirrored networking

Status: **code complete, green (2131 passed, 2 skipped), pint + phpstan clean.**
Not yet `./build`-ed or verified live end-to-end. Built 2026-09-05.

Improves the Windows-laptop DX for `larakube up`/`setup` on WSL2: the Windows
browser must resolve `*.kube` hosts to the cluster ingress, which needs a
Windows hosts-file entry that (a) actually gets written through an elevated
prompt, and (b) points at a stable IP so it doesn't rot on every reboot.

## What shipped

### 1. Fix: elevated Windows hosts sync silently no-op'd
`InteractsWithHosts::syncWindowsHostsFile()` staged the `.ps1` + hosts content
in WSL `/tmp`. `wslpath -w` turns that into `\\wsl.localhost\<distro>\tmp\…`, a
per-user 9P share the **Administrator logon session** created by
`Start-Process -Verb RunAs` cannot read — so the elevated copy "succeeded" while
copying nothing (blank Admin PowerShell window just sits; entry ends up missing).
NOT a UAC/password/syntax problem.

- Fix: `windowsTempDir()` resolves `%TEMP%` → `/mnt/c/Users/<you>/AppData/Local/Temp`;
  stage there so `wslpath -w` yields a native `C:\…` path the elevated token can read.
- Files: `app/Traits/InteractsWithHosts.php` (`syncWindowsHostsFile`, new `windowsTempDir`).
- Tests: `tests/Unit/InteractsWithHostsProcessTest.php` (`windowsTempDir` resolves/null).

### 2. Feature: WSL mirrored networking → stable 127.0.0.1
The WSL node IP (`resolveIngressIp()` → node InternalIP, e.g. 172.30.x.x) changes
on every `wsl --shutdown`/reboot, staleifying the Windows hosts entry (→ another
admin re-sync). Mirrored networking shares localhost, so the ingress is reachable
from the Windows browser at a stable `127.0.0.1`.

- Detection: `DetectsWsl::wslNetworkingMode()` / `mirroredNetworkingActive()`
  (via `wslinfo --networking-mode`, returns `nat`/`mirrored`),
  `mirroredNetworkingRequested()` (reads `~/.wslconfig`), `mirroredRestartPending()`,
  `wslConfigPath()`.
- Hosts IP: `InteractsWithHosts::windowsReachableIngressIp()` → `127.0.0.1` when
  mirrored active, else `resolveIngressIp()`. Used by BOTH Windows-hosts writers
  (`syncWindowsHosts`, `ensureWindowsHostsAreSet`); the Linux `/etc/hosts` writer
  (`syncHostsEntries`) still uses the node IP.
- Setup: `ConfiguresWslNetworking` (new trait) — `ensureMirroredNetworking()` is
  `setup` Step 6 (self-guards on `isWsl()`); offers to patch `~/.wslconfig` with
  `networkingMode=mirrored` (idempotent INI merge `withMirroredNetworking()`),
  reminds the user to `wsl --shutdown`. Idempotent on re-run: active → note;
  configured-but-pending → remind (no re-prompt); neither → offer.
- Nudge: `UpCommand` (local env) re-surfaces the `wsl --shutdown` reminder when
  `mirroredRestartPending()`, so a forgotten restart can't hide.
- Requires Windows 11 22H2+ / WSL 2.0.0+ (james's box: 24H2 build 26100, WSL 2.7.12 — OK).
- Tests: `tests/Unit/ConfiguresWslNetworkingTest.php` (pure INI transform),
  `tests/Unit/DetectsWslTest.php` (mode/requested/restart-pending detection).

## TODO — verify live on the WSL box (do these after switching machines)

1. **`./build`** (james runs it — never the agent) to compile the phar with all of the above.
2. **Hosts-sync fix:** `cd ~/projects/wordpress && larakube up`, answer **Yes** to the
   Windows admin prompt, accept UAC → confirm the entry actually lands in
   `C:\Windows\System32\drivers\etc\hosts` and `http://wordpress.kube` resolves in the
   Windows browser. (Before the fix it silently didn't.)
3. **Mirrored networking end-to-end:**
   - `larakube setup` → accept the mirrored-networking offer → confirm `~/.wslconfig`
     gets `[wsl2]\nnetworkingMode=mirrored` and the `wsl --shutdown` reminder prints.
   - Run `larakube up` again BEFORE restarting → confirm the pending-restart nudge appears.
   - `wsl --shutdown` (from Windows PowerShell), reopen terminal, `wslinfo --networking-mode`
     → `mirrored`.
   - `larakube up` → confirm the Windows hosts entry is now `127.0.0.1 …` and stays put
     across a reboot (no re-sync prompt on the next `up`).
   - Sanity: nothing on Windows was already bound to 80/443 (checked 2026-09-05: only
     `wslrelay.exe`, WSL's own forwarder — no real conflict).

## TODO — optional follow-ups (not started)

- **Docs:** add mirrored networking as the recommended WSL setup to README /
  `https://cli.larakube.app/onboarding/operating-systems/windows`. (Offered, not done.)
- Consider having `setup` verify 80/443 are free before recommending mirrored, and warn
  if a real Windows service holds them.

## Memory
Non-obvious gotchas saved to agent memory: `wsl-windows-hosts-and-networking.md`
(the `\\wsl.localhost` elevated-token invisibility; mirrored → 127.0.0.1 wiring;
`isWsl()` is true on james's dev box so no test drives `setup`'s full `handle()`).
