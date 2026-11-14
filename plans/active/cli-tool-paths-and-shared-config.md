# Persist each CLI tool's location and version in ~/.larakube/tools.json

## Context

Today a tool's location and version are worked out again and again:
- The CLI re-resolves the location on every run (`AbstractCliTool::resolveBinary()`: `command -v`, then candidate paths).
- Desktop asks the CLI (`setup:status <tool> --json`) and probes versions, keeping both in its **database-backed** cache (`CACHE_STORE=database`, `readiness.tool.{slug}`, 5 minutes). A cold lookup starts a CLI process, and every expiry re-runs `--version`.

A tool's location and version change only on install, update, or the binary being replaced or removed. The plan: the CLI records both in a file under `~/.larakube`, together with a cheap fingerprint that shows when either has gone stale. The tool classes get the store injected, and Desktop reads the same file. There are no database rows for tools, and a process starts only when something actually changed.

**A dedicated `~/.larakube/tools.json`, not `config.json`** (decided). `GlobalConfigData::save()` rewrites accounts, tokens, stacks and tofu passphrases from each process's in-memory copy. A tool-path save from one of Desktop's many parallel CLI runs could overwrite a passphrase a concurrent `cloud:create` had just written. In a separate file, a race only drops a cache entry.

## One file per side; no reconciliation between them

Windows and WSL each have their own `~/.larakube`, so each side's CLI writes a `tools.json` describing only the binaries on its own side. kubectl and the CLI legitimately exist on both sides, and each copy has a different job.

| File | Written by | Holds | Read by Desktop for |
|---|---|---|---|
| `%USERPROFILE%\.larakube\tools.json` (macOS/Linux: `~/.larakube/tools.json`) | host `larakube` / `larakube.exe` | host tools: kubectl, k9s, tofu, aws, gcloud (+ gh, tea off Windows), and `larakube` itself | every runtime lookup, and the Setup rows |
| `/home/larakube/.larakube/tools.json` in larakube-ubuntu | the distro's `larakube` | the distro's kubectl, gh, tea, and `larakube` itself | Windows Setup's WSL rows ("Bundled in larakube-ubuntu", the `larakube-wsl` row) |

- **Which copy Desktop uses is decided by the file it reads, not by merging.** Runtime lookups (`ToolLocator::find`) read only the host file, so the Windows kubectl always wins and the distro's kubectl only ever serves the distro's CLI.
- **Desktop reads the distro file over its Windows path.** `WslDistro::toWindows('/home/larakube/.larakube/tools.json')` gives the `\\wsl.localhost\…` path, the same way `GlobalSettings::wslConfigPath()` already reads the distro's `config.json`. Only the Windows Setup screen reads it, because that path wakes the distro.
- **The CLI records itself.** On each run it writes its own entry (`larakube`: path, version, fingerprint) only when that entry changed. The path comes from `Phar::running()`, falling back to argv[0]; the version from the app version.
  - This works because the CLI knows its own version without running anything.
  - Desktop can then show both CLIs' versions (host and WSL) without spawning `larakube --version`.
  - Desktop still locates the CLI binary itself the first time, before any file exists. That bootstrap step can't come from a file the CLI writes.

## Share `config.json` between Windows and WSL (closes the deferred `~/.larakube` split)

`tools.json` stays per side. Its paths only mean something on their own side, and a shared file would have both CLIs overwrite each other's `kubectl` entry. `config.json` is the opposite. It holds data that must agree on both sides: servers and stacks, cloud accounts and tokens, tofu passphrases, the local domain suffix, email, and Desktop's own keys.

- **Link it the way `~/.kube` already is** (commit `1c1b0850`):
  - `WslBridge::dispatchToWsl()` also passes `LARAKUBE_HOST_CONFIG=/mnt/<drive>/…/.larakube/config.json`, created as `{}` on the host if missing.
  - The entrypoint keeps the distro's `~/.larakube/config.json` a symlink to that file.
  - Generalize `WslBridge::linkHostKubeDirectory()` into `linkToHost(string $hostPath, string $distroPath)`, used for both the `.kube` folder and `config.json`.
  - An existing real `config.json` in the distro moves to `config.json.distro`, never deleted. Values only it had (e.g. an email entered during `new`) get asked for again.
- **Make the atomic write follow links.** `InteractsWithJsonFile::atomicWriteJson()` writes `path.tmp<pid>` and then renames it onto `path`, which would replace the symlink with a regular file on the first save. When `path` is a link, it will write the temp file next to the link's target and rename onto the target, so the link survives. Renaming works on `/mnt/c` (drvfs).
- **Desktop drops its hand-rolled mirror.** `GlobalSettings::writeConfig()` currently copies the whole host file over the distro's `config.json` (`wslConfigPath()`), overwriting anything the WSL CLI saved there. `readConfig()` falls back to that copy. Both go: Desktop reads and writes only the host file.
- **Accepted trade-offs:**
  - Mode 0600 can't be enforced on `/mnt/c`. The distro has a single user, and the Windows file keeps its profile ACLs.
  - `gcpCredentials` may hold a Windows file path, which is meaningless inside WSL. It's only used by cloud commands, and those run on Windows.
  - The existing read-modify-write race in `GlobalConfigData::save()` now spans both sides. It isn't new and is unchanged in kind.

## Entry shape and freshness

```json
{
  "kubectl": {
    "path": "/opt/homebrew/bin/kubectl",
    "real": "/opt/homebrew/Cellar/kubernetes-cli/1.34.1/bin/kubectl",
    "size": 57017234,
    "mtime": 1789000000,
    "version": "Client Version: v1.34.1",
    "channel": "homebrew"
  }
}
```

- **Location valid** if `path` still exists and is executable (`is_file` on Windows). If not, evict it and re-resolve.
- **Version valid** if `realpath(path)`, size and mtime all still match. That's a stat, not a process.
  - `brew upgrade` changes the real path (a new Cellar folder).
  - A re-download, MSI repair or OrbStack update changes the size or modification time.
  - Any mismatch re-probes the version and rewrites the entry.
- `install()` / `update()` / `reinstall()` rewrite the entry after success.
- `setup:status` re-resolves fully: the explicit "check again".
- **Behavior change:** between refreshes, the recorded path wins over PATH order. That makes it deterministic: the same binary every run, the one Desktop shows.

## Changes

**CLI** (the only writer, per ADR 0001)
- New `app/Services/CliTools/ToolPaths.php` (container singleton):
  - Loads `home_path('.larakube/tools.json')` once per process and exposes `entry/remember/forget`.
  - Writes only on change, using the atomic JSON write `GlobalConfigData::save()` already uses, with mode 0600.
  - Computes fingerprints with `realpath`/`filesize`/`filemtime`.
- `app/Services/CliTools/AbstractCliTool.php`:
  - Constructor-injected `ToolPaths`.
  - `resolveBinary()`: recorded path if valid, otherwise evict, resolve and remember.
  - `version()` / `status()`: the recorded version if the fingerprint matches, otherwise probe and remember.
- `app/Enums/CliTool.php`: `service()` resolves the tool classes through the container.
- Self-record of the `larakube` entry: a small listener on command start in a service provider.
- `app/Commands/SetupStatusCommand.php`: always resolves fresh.

**Desktop** (a reader only)
- `app/Services/LaraKube/ToolLocator.php`:
  - `find()` for setup-managed tools reads the host `tools.json` entry, valid if the path exists.
  - On a miss it asks `setup:status <tool> --json` once, which writes the file.
  - If the CLI can't answer, it falls back to Desktop's own lookup.
  - The `readiness.tool.*` path caching and the `CLI_UNAVAILABLE_KEY` database entry are removed.
- `app/Services/LaraKube/ReadinessCheck.php`:
  - Setup rows take location, version and owner from the file when the fingerprint matches, and only call `setup:status` otherwise.
  - The `larakube` and `larakube-wsl` rows read the CLI's self-recorded entries from the host and distro files. They fall back to today's `--version` probe when an entry is missing.
- `tests/TestCase.php`: the "old CLI" default becomes simply "no `tools.json`", which is the natural state of the sandbox home.

## Tests
- **CLI `ToolPathsTest`:**
  - remembers and verifies an entry;
  - evicts a vanished path;
  - re-probes the version when size, mtime or real path change, and doesn't when they're unchanged (no process runs);
  - never touches `config.json`.
- **CLI `CliToolTest`:** a recorded path is used without `command -v`; a reinstall rewrites the entry; the CLI's own entry updates only on change.
- **Desktop:**
  - `find('kubectl')` reads the file with no process run;
  - Setup shows a version straight from the file;
  - a missing or vanished entry asks the CLI once;
  - the Windows WSL rows read the distro file through `WslDistro::toWindows()`;
  - no `readiness.tool.*` database rows are written.
- Full suites: CLI `composer test`, Desktop `php artisan test --parallel` (one heavy process at a time).

## Verification
After the user runs `./build`:
1. **macOS:** run `larakube setup:status`. Check that `~/.larakube/tools.json` has the tools plus `larakube`, and that `config.json` is unchanged. Then run `brew upgrade k9s`: the next lookup re-probes only k9s's version.
2. **Windows `config.json` sharing:**
   - After any dispatched WSL command, `wsl -d larakube-ubuntu ls -l ~/.larakube/config.json` shows a link to `/mnt/c/Users/<you>/.larakube/config.json`.
   - A setting saved in Desktop, or a server added by `cloud:create` on Windows, is visible to `larakube` inside WSL.
   - After a WSL-side save, the link is still a link.
3. **Windows:** check the host `tools.json` holds host tools and `larakube.exe`. After any dispatched WSL command, the distro's `tools.json` holds the distro's kubectl/gh/tea/larakube. Setup's WSL rows show those versions with no `wsl.exe … --version` runs.
3. **Desktop:** once `tools.json` exists, the Setup screen loads its rows with no `setup:status` or `--version` processes.
