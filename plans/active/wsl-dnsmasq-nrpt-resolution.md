# WSL: resolve `*.{tld}` the same way as macOS/Linux (dnsmasq + Windows NRPT), instead of per-host Windows-hosts-file syncing

## Context

Local dev host resolution today has two completely different mechanisms:

- **macOS/Linux**: `larakube setup`/`trust` installs dnsmasq, wildcards
  `*.{tld} → 127.0.0.1` (`InteractsWithTrust.php::configureDnsmasq()`), and
  every project "just works" with zero per-host admin prompts.
- **WSL2**: dnsmasq is explicitly skipped (`setupDnsmasq()` returns immediately
  if `isWsl()`), because dnsmasq running *inside* the WSL VM is invisible to
  the Windows browser that actually renders pages. Instead, every `up`,
  Companions install, and Plex init writes directly into the **Windows**
  hosts file via a UAC-elevated PowerShell (`InteractsWithHosts.php`:
  `ensureWindowsHostsAreSet()`, `syncWindowsHosts()`), re-done per host,
  per project, with the ingress IP re-resolved (and potentially stale) every
  time, admitted in the code's own comments as "unreliable — it can just
  flash a window and fail."

The user's prompt for this: since LaraKube ships its own custom WSL distro
(`larakube-ubuntu`, not stock Ubuntu), we control what's installed in it —
so dnsmasq doesn't have to be "invisible to Windows" anymore. I verified via
web search that **WSL2 mirrored networking mode** (`networkingMode=mirrored`
in `.wslconfig`, Windows 11 22H2+, which `larakube setup` already offers to
enable via `ConfiguresWslNetworking::ensureMirroredNetworking()`) shares
`127.0.0.1` between Windows and the WSL VM — confirmed by Microsoft's own
WSL networking docs. Combined with a one-time Windows **NRPT** rule
(`Add-DnsClientNrptRule -Namespace ".test" -NameServers "127.0.0.1"`,
admin-elevated once, not per-host) that tells Windows's resolver to ask
`127.0.0.1` for that TLD, Windows can query a dnsmasq running inside WSL
directly. This exact pattern (WSL2 + dnsmasq + NRPT) is independently
documented as working by a comparable tool (Reward,
rewardenv.readthedocs.io), which explicitly calls out that it works under
WSL2. The known caveat: NRPT only affects apps using Windows's **built-in**
resolver — a browser with its own DNS-over-HTTPS resolver could bypass it.

**I checked `larakube-ubuntu` directly, per your ask**: the Dockerfile,
`wsl.conf`, and `scripts/*.sh` have zero mentions of hosts, dnsmasq, resolv,
or DNS of any kind. There is no existing "hosts file connected to Windows"
setting in that repo — you may be thinking of WSL's own *default*
`generateHosts` behavior (copies some Windows-side info into WSL's
`/etc/hosts`, the opposite direction of what we need), which isn't something
LaraKube configured.

This plan **removes the fluff you called out** (per-host Windows-hosts-file
sync, the admin-elevated `.ps1` staging, the "flash and fail" flakiness) for
the common case — WSL2 + mirrored networking — by making WSL use the exact
same dnsmasq-wildcard mechanism macOS/Linux already use, instead of a
separate bespoke system. The old Windows-hosts-file path is **kept only as
an automatic fallback** for machines that can't/won't run mirrored
networking (Windows 10 doesn't support mirrored mode at all), so nothing
regresses for them.

## Approach

### 1. `larakube-ubuntu`: install dnsmasq in the image

Add `dnsmasq` to the Dockerfile's apt install list (same package the
macOS/Linux path already shells out to install). Verify at build time
whether `systemd-resolved` is present/active by default in the minimal
Ubuntu 24.04 base — `routeResolvedToDnsmasq()` (the Linux path's existing
systemd-resolved drop-in logic) depends on it being active; if the base
image doesn't enable it under `systemd=true` out of the box, add it
explicitly. No other Dockerfile changes — `configureDnsmasq()`'s existing
Linux branch (conf file, `systemctl restart dnsmasq`, resolved drop-in) is
already OS-generic and needs no WSL-specific fork.

### 2. `cli`: let dnsmasq run on WSL, gated on mirrored networking

- **`InteractsWithTrust.php::setupDnsmasq()`**: replace the unconditional
  `if ($this->isWsl()) { return; }` with a check against mirrored networking
  being active (reuse `ConfiguresWslNetworking::mirroredNetworkingActive()`).
  Only mirrored WSL proceeds to install/configure dnsmasq exactly like
  Linux; non-mirrored WSL still returns early (today's behavior, unchanged).
- **New: a one-time Windows NRPT rule setup.** New method(s) in
  `InteractsWithTrust.php` right alongside `configureDnsmasq()` (NOT in
  `ConfiguresWslNetworking.php` — that trait's `ensureMirroredNetworking()`
  reads `$this->reminders`, which only `CollectsReminders`-composing Command
  classes declare; `InteractsWithTrust` is composed far more broadly, so
  merging the two traits made PHPStan flag `$reminders` as undefined on
  ~60 unrelated commands. `InteractsWithTrust` already has `LaraKubeOutput`
  and `DetectsWsl` composed, which is everything the NRPT methods need),
  e.g. `ensureWindowsNrptRule(array $tlds)`:
  - Checks existing rules via `Get-DnsClientNrptRule` (idempotent — skip if
    already covering the TLD, extend in place like `configureDnsmasq()`
    already does for multiple TLDs).
  - Applies via an elevated PowerShell (`Start-Process -Verb RunAs`), same
    staging pattern `syncWindowsHostsFile()` already uses (stage on the
    Windows filesystem, not WSL's `/tmp` — same 9P-share trap documented
    there).
  - Called once from `SetupCommand`'s Step 4 (today just a comment: "WSL2
    host resolution is handled Windows-side") when WSL + mirrored are both
    active, and again wherever `configureDnsmasq($tld)` is called for a
    *new* TLD (`ensureHostsAreSet()`'s dnsmasq-extend branch,
    `ConfigTldCommand`'s dnsmasq-extend branch) so a project-pinned TLD via
    `config:tld --project` gets the same NRPT coverage, not just the global
    default.
- **`InteractsWithHosts.php::ensureHostsAreSet()`**: the two
  `! $this->isWsl() && $this->dnsmasqCoversKube($tld)` guards (lines ~87,
  212) drop the `! $this->isWsl()` exclusion — `dnsmasqCoversKube()` is
  already OS-generic (Darwin vs. Linux, and WSL counts as Linux), so once
  dnsmasq+NRPT are confirmed live it returns true on WSL too and the method
  returns early, skipping `ensureWindowsHostsAreSet()`/the Windows-hosts
  sync entirely. When dnsmasq isn't covering yet (mirrored networking
  declined, or Windows 10), execution falls through to today's
  Windows-hosts-file sync exactly as now — this is the fallback path, not
  deleted.
- **`InteractsWithHosts.php::dnsmasqFullyCoversKube()`** (new, shared): wraps
  `dnsmasqCoversKube($tld) && (!isWsl() || windowsNrptCoveredTlds() has $tld)`
  in one place, so `ensureHostsAreSet()`, `ManagesCompanions`, and
  `PlexInitCommand` all ask the same question instead of duplicating it.
- **`ManagesCompanions.php`/`PlexInitCommand.php`**: their `syncWindowsHosts()`
  calls now check `dnsmasqFullyCoversKube()`, so a dnsmasq+NRPT-covered WSL
  machine stops needing per-companion Windows-hosts syncing too.
- **`DoctorCommand`/`trust:check` (`diagnoseTrustChain()`)**: no changes
  needed directly — `isDnsmasqConfigured()` (which it already calls) now
  itself requires `windowsNrptCoveredTlds()` to cover the TLD on WSL, on
  top of the existing `getent hosts` probe, so the single source of truth
  stays correct without a second check bolted on at the diagnostic layer.

### 3. Known open verification (can't be checked from here — flag during implementation)

- **Ingress IP under mirrored networking**: `buildDnsmasqConf()` hardcodes
  `address=/.{tld}/127.0.0.1` on macOS/Linux, assuming the local ingress
  LoadBalancer is reachable at `127.0.0.1`. Under WSL2 + mirrored
  networking this should hold the same way (shared loopback), but
  `resolveIngressIp()`'s existing WSL comments describe a *non-mirrored*
  reality (separate loopbacks, node InternalIP instead) — confirm on a real
  Windows 11 + mirrored-networking machine that Traefik's local
  LoadBalancer/NodePort is actually reachable at WSL's own `127.0.0.1`
  before wiring dnsmasq's WSL conf to the same hardcoded `127.0.0.1` the
  other platforms use; fall back to `resolveIngressIp()`'s resolved IP if not.
- **systemd-resolved presence** in the minimal Ubuntu 24.04 Docker-built
  rootfs under `systemd=true` — confirm it's active by default, or add it
  explicitly to the Dockerfile's apt install list.

### 4. Tests

Extend existing coverage rather than add new files where these already own
the behavior:
- `cli/tests/Unit/DnsmasqMultiTldTest.php` — add a WSL+mirrored case.
- `cli/tests/Unit/InteractsWithHostsProcessTest.php` — assert
  `ensureWindowsHostsAreSet()` is skipped when `dnsmasqCoversKube()` is true
  on WSL, and still runs when it's false (the fallback path).
- `cli/tests/Unit/InteractsWithTrustProcessTest.php` — `setupDnsmasq()`'s
  new mirrored-vs-non-mirrored WSL branching.
- `cli/tests/Feature/SetupRuntimeInstallTest.php` / `SetupProfileTest.php` —
  Step 4's new WSL+mirrored dnsmasq-install path.
- New unit test for the NRPT rule builder (pure string/parse logic, same
  style as `ConfiguresWslNetworking::withMirroredNetworking()`'s existing
  test) — idempotent extend-in-place behavior for multiple TLDs.

## Critical files

- `larakube-ubuntu/Dockerfile`, `README.md` — added `dnsmasq`/`systemd-resolved`.
- `cli/app/Traits/InteractsWithTrust.php` — `setupDnsmasq()`'s mirrored-gated
  WSL branch, `isDnsmasqConfigured()`'s WSL+NRPT check, and the 3 new NRPT
  methods (`ensureWindowsNrptRule()`, `windowsNrptCoveredTlds()`,
  `applyWindowsNrptRule()`) called from inside `configureDnsmasq()`.
- `cli/app/Traits/InteractsWithHosts.php` — the two `ensureHostsAreSet()`
  guards now call the new shared `dnsmasqFullyCoversKube()`.
- `cli/app/Traits/ManagesCompanions.php`, `cli/app/Commands/Plex/PlexInitCommand.php`
  — their `syncWindowsHosts()` calls use `dnsmasqFullyCoversKube()` too.
- `cli/app/Commands/SetupCommand.php` — Step 4 (mirrored networking) moved
  before Step 5 (dnsmasq), so a same-run dnsmasq setup can see mirrored
  networking that was just enabled on a *previous* run.
- `cli/app/Traits/DetectsWsl.php` — `windowsTempDir()` moved here from
  `InteractsWithHosts.php` (needed by both, and this avoids a trait cycle).

## Verification

- `composer format && composer analyse && composer test` (full gate, per
  repo convention) after each logical chunk (larakube-ubuntu image change
  can't be test-suite-verified — flag to the user to rebuild/import the
  distro and manually confirm `wsl --shutdown` + a fresh `larakube setup`
  on a real Windows 11 + mirrored-networking machine, since no CI here can
  exercise actual WSL2/NRPT/UAC behavior).
- Re-run the two "open verification" items above on real hardware before
  calling this done — they're genuine unknowns, not assumptions I'm
  confident enough in to skip.

## Status (implementation pass, 2026-10-17)

Code-complete in `cli` and `larakube-ubuntu`. Full gate green:
`composer format` (0 changes), `composer analyse` (0 errors),
`composer test` (3268 passed, 4 pre-existing environment skips unrelated
to this change). Still needs the two "open verification" items above
confirmed on a real Windows 11 + mirrored-networking machine before this
is battle-tested — flag to the user per AGENTS.md's "Battle-Testing"
standard (`./build` + `larakube setup` on real hardware), since nothing in
this repo's test suite can exercise actual WSL2/UAC/NRPT behavior.
