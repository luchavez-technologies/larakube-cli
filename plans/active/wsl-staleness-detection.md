# Restore WSL distro staleness detection, properly this time

**Status: DONE.** Shipped in `desktop` commit `00d8a8c` — `isStale()` now
compares a locally-recorded import checksum against a fresh
`expectedChecksum()` fetch, `check()`'s `'stale'` branch is restored, and
`WslTest.php` covers match/mismatch/missing-marker/import-records-checksum.
Kept here for context on *why* the old dnsmasq probe was removed and what
replaced it, in case it comes up again.

## What happened

`Wsl::isStale()` (`desktop/app/Services/Wsl.php:60-63`) is currently a dead
stub (`return false;`), and `check()` no longer calls it at all. Git history
explains why: `7b3692e` originally shipped it checking for one specific
marker (`which dnsmasq` inside the distro — a proxy for "built before
dnsmasq was added to the image"), then `5a0f985 fix(desktop): remove
fragile dnsmasq stale check from wsl readiness` ripped it out because that
signal only catches ONE specific past change — any other future change to
the image (a new tool, an updated base package, anything besides dnsmasq
specifically) would leave `isStale()` reporting "fine" when it isn't. That
removal was correct; the dnsmasq probe was never going to generalize.

## The general fix

`Wsl::download()` already does exactly the comparison needed, just doesn't
persist it: `expectedChecksum()` (`Wsl.php:454-463`) fetches the freshly
published `.sha256` for `releases/latest/download/larakube-ubuntu-amd64.tar.gz`
(`imageUrl()`, config `larakube.rootfs_url` — always targets `latest`, the
dated stable channel, never the rolling `canary` tag, so no tag-never-changes
complication to worry about here). It's used once, transiently, to validate
a download — then thrown away.

1. **`import()`**: after a successful import, write
   `hash_file('sha256', $this->imagePath())` to a small local marker file
   (e.g. `installDirectory().'/imported.sha256'`) recording what was
   actually imported.
2. **`isStale()`**: read that marker file. Missing (pre-upgrade installs
   that predate this fix) → `true`, safe default, prompts an update rather
   than silently assuming fresh. Present → compare against a fresh
   `expectedChecksum()` call (the exact same private method `download()`
   already uses — no new fetch logic). Mismatch → `true`. Match → `false`.
   `expectedChecksum()` returning `null` (network failure) → `false`
   (fail open — don't nag the user with a false "stale" over a failed
   informational check, consistent with this codebase's existing
   fail-open posture for similar checks).
3. **`check()`**: restore the staleness branch that `5a0f985` removed,
   right before the `'ready'` result — same shape as before, just backed
   by the new `isStale()`.
4. **Tests**: `desktop/tests/Feature/WslTest.php` already has the exact
   fixture pattern needed (`Http::fake(['*.sha256' => ...])`, from the
   existing "downloaded and checked against its published checksum" test).
   Add cases: marker matches expected → not stale; marker differs → stale;
   marker missing → stale; `expectedChecksum()` fails (network) → not
   stale (fail open); `check()` end-to-end returns `'stale'` when
   `isStale()` is true; `import()` writes the marker file on success.

No frontend changes needed — `wsl-check.tsx`'s `'stale'` state UI is
untouched and already complete.
