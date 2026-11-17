# Setup tabs per side, and a safer shared config.json

Status: built (Desktop d0772f2; CLI in the commit that adds this plan). Awaiting Windows testing.

## Context

On Windows, LaraKube Linux (ADR 0022, Desktop) now bundles the CLI, kubectl, aws, gcloud,
gh, tea, git, Podman and k3s. The Windows host has its own larakube.exe, kubectl, tofu, k9s,
hcloud and optional aws and gcloud. The Setup page lists both sides in one card, followed by
a "Included in LaraKube Linux" list, so it keeps growing and the overlap is hard to read.

`~/.larakube/config.json` is shared between the two sides by a symlink (WSL links to the
Windows file). Two problems follow from that:

1. **Host-specific values.** `gcpCredentials` can be a Windows path (`C:\…\key.json`).
   A server's `sshKey` already goes through `SshKeyPath::forThisSide()`. `gcpCredentials`
   does not.
2. **Lost updates.** Every save reads the whole file, changes it and writes it all back. A
   Windows save (Desktop, server commands, the update check) and a WSL save (`cloud:deploy`,
   project commands) at the same time means the later one silently drops the earlier one's
   change. File locks are not reliable across the Windows/WSL boundary (drvfs/9P).

## Part 1: Setup tabs (Desktop)

- On Windows with `usage === 'apps'`, the "Command-line tools" card has two tabs:
  - **This computer**: catalog entries whose `environment` is `windows`. Install, update
    and reinstall work as before, with "Install everything" scoped to this tab.
  - **LaraKube Linux**: catalog entries whose `environment` is `wsl` (the CLI inside WSL,
    which stays actionable), plus the image's bundled tools as read-only rows. The image
    updates those rows.
- A tool present on both sides (larakube, kubectl, aws, gcloud) carries an "Also in
  LaraKube Linux" or "Also on Windows" badge. The Windows/WSL environment badge on each row
  is dropped inside tabs, since the tab already says which side it is.
- macOS, Linux, and Windows without local development keep the single list.
- The LaraKube Linux tab never wakes the distro:
  - `Wsl::bundledTools()` caches the manifest until the image changes (import, update
    and reset already forget the cache), not for 10 minutes.
  - It reads the manifest only while the distro is running. When the distro is asleep and
    nothing is cached, the tab shows the Wake up button.
- The tab opens on "This computer", or on LaraKube Linux when Setup is opened to highlight one
  of its tools. (Remembering the tab per viewer was dropped as not worth the state.)

## Part 2: config.json (CLI)

- `gcpCredentials` goes through the same per-side translation as SSH keys when it is a
  path, never when it holds inline JSON. `App\Support\HostPath` does the drive-path
  translation in both directions (`C:\…` ↔ `/mnt/c/…`) and `SshKeyPath` builds on it.
- Saves merge instead of overwriting. `GlobalConfigData::save()` re-reads the file just
  before writing and applies only what this copy changed since `load()`. Maps (`stacks`,
  `cloudAccounts`, `tofuPassphrases`, …) merge entry by entry, so two writers adding or
  editing different servers both keep theirs. Lists and scalars are replaced whole when
  changed. A removal stays removed, and an entry another process removed is never brought
  back. That shrinks the race from "the whole command" to the moment of the write.
- Dropped: moving `latestVersion`, `latestVersionCheckedAt` and `lastStarPromptAt` into a
  per-side state file. With the merge, writing them no longer erases anything else, so
  the move bought nothing.
- Not done here: a single writer (WSL routing every config write to the Windows CLI). That
  is fully race-free but heavy; revisit only if merged writes still lose data in practice.

## Verification

- Desktop: Readiness tests for the tab split, the badges, and bundled tools not reading the
  distro while it is asleep. Full suite.
- CLI: tests for the merge (two loaded copies, each changing a different key, both
  survive), the state-file move, and `gcpCredentials` translation. Full suite.
- Windows (the user): Setup shows both tabs, the WSL tab shows the bundled versions, and
  the tab stays readable while the distro is asleep.
