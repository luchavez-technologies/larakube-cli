# Desktop: backups per server

**Status:** planned, not started. Not part of 0.0.1.

## Context

The CLI already backs a cluster up: `backup:init` (destination), `backup:run`, `backup:schedule` / `backup:unschedule`, `backup:list`, `backup:prune`, `backup:restore`. Volumes of every Cluster Tool are found automatically, archives are encrypted, and a restore drill has been proven. Desktop has none of it: its only "backup" is a Settings button that copies `~/.kube/config` (`context:backup`), which protects the list of connections and no data.

A student's server holds real data (chat, wiki, passwords), so Desktop should make "is this server backed up, and can I get it back" visible and one click away.

## What the user sees

A **Backups** section on each server's page (`servers/show.tsx`), plus a warning on the Fleet dashboard.

| State | Shown | Actions |
| --- | --- | --- |
| Not set up | "This server has no backups." | **Set up backups** |
| Set up | Destination (bucket and endpoint), schedule in plain words ("every night at 03:00"), last backup (time, size, complete or not), count of backups kept, any incomplete ones | **Back up now**, **Change schedule**, **Stop schedule** |
| Backups exist | A table of backups (taken, size, what is in each) | **Check a backup** (`restore --deep`, nothing is changed), **Restore…**, **Clean up old backups** (`prune`, preview first) |

The Fleet dashboard gets a card, "N servers have no backups", linking to those servers.

## Set up flow

1. Pick a destination. **Cloudflare R2** is the recommended path (the CLI can create the bucket from an API token); others take endpoint, bucket and keys (S3-compatible).
2. Pick a schedule from presets (nightly, weekly) or a cron expression, with the timezone.
3. Run `backup:init` then `backup:schedule`.
4. **Recovery card.** The CLI writes the passphrase and keys to `~/.larakube/backup-recovery.txt` (0600). Desktop must not stream the passphrase into the run log. Instead it shows "Save your recovery card" with the file path, a Reveal in folder button and a checkbox "I saved a copy somewhere that is not this computer or this server". Without the passphrase the archives cannot be read, so setup is not marked done until the box is ticked.

## Restore, with care

- **Check a backup** is always safe: `backup:restore --deep --dry-run`.
- **Restore** is destructive: `--database=` or `--volume=` replaces live data. Require typing the server and item name, offer the check first, and say which service will be scaled down.
- A **server that is gone** is restored from a machine with only the recovery card, so Desktop also offers "Restore from recovery card" (endpoint, bucket, keys, passphrase) for a new server.
- Postgres restores have known traps (no drop, tenant ownership). Reuse the CLI's own handling and never rebuild it in Desktop.

## Work, in order

1. **CLI: `backup:status {environment} --context= --json`** (read-only). Returns `configured`, `destination {endpoint, bucket, region}` (never keys or the passphrase), `schedule {cron, timezone, suspended, lastScheduleTime, lastSuccessfulTime}`, `last {id, taken, sizeBytes, complete}`, `count`, `incomplete`, `recoveryCardPath`. Add `--json` to `backup:list` (id, taken, size, contents, complete) and a structured result for `backup:run`, in the same style as the other `--json` commands (progress on stderr, one JSON line on stdout). Tests with fakes, like the existing backup tests.
2. **Desktop services:** `BackupStatus` (reads `backup:status`, remembered a few minutes, forgotten when a backup run ends, like Setup's tool results) and controller actions that start runs through `CliRunner` (`RunKind::BackupInit`, `BackupRun`, `BackupSchedule`, `BackupPrune`, `BackupRestore`).
3. **Desktop UI:** the Backups section, the set-up flow and recovery-card step, the backups table, and the dashboard card. Reuse `Run` pages for progress, like Tools.
4. **Restore:** the check, then the typed-name confirmed restore, then restore from a recovery card.
5. **Docs:** a Backups page under LaraKube Desktop, and a note in Servers.

## Rules this must keep

- Secrets (keys, passphrase) are passed by environment and never stored on a `Run` or shown in its log. Desktop never reads the recovery file's contents back into the app.
- No restore or prune runs without the preview first and a named confirmation (no destructive steps over live data).
- Every action is a CLI command shown in Activity; Desktop adds no backup logic of its own.
- Server and environment are named the way the CLI names them (`--context`), never derived in Desktop.

## Open questions

- Which destinations to offer first: R2 only (it has bucket creation), or R2 plus a generic S3 form.
- Whether one set of backup credentials can be shared across a user's servers, or each server needs its own.
- Where "back up before a risky action" fits (for example before removing a tool), later.

## Verification

CLI: the new `backup:status` and `--json` tests, full `pest --parallel`, PHPStan. Desktop: feature tests with a faked CLI for each action, `composer ci:check`. Manual, against a throwaway server: set up, back up now, list, check a backup, restore one non-critical volume, and confirm the recovery card file is written and the passphrase appears nowhere in Activity.
