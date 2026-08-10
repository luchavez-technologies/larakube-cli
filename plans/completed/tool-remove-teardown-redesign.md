# Plan: `*:remove` teardown redesign — safe-by-default, opt-in destruction

**Status:** Not started. Design fully settled (decisions below are final).
**Scope:** `AbstractToolRemoveCommand` (one file owns it for all 24 tools) + the
storage-bucket helper + `*:init` reattach messaging + tests.

## Problem
Today `*:remove` is **destroy-by-default**: without `--keep-data` it drops the
Commons Postgres tenant. One forgotten flag = irreversible data loss. Also
inconsistent: it drops the DB but never the S3 buckets, and deletes the PVC via
the namespace delete. The teardown warning even lies ("all data goes with it").

## Decisions (final)
1. **Invert the default → safe-by-default.** Replace `--keep-data` with
   **`--purge`** (opt-in destruction). Bare `*:remove` preserves persistent data.
2. **One flag, `--purge`** — not `--with-data`/`--with-files`. A tool with its DB
   dropped but files orphaned (or vice-versa) is worse than all-or-nothing.
3. **S3 buckets are NEVER deleted** (even on `--purge`) — preserve always. On
   `*:init`, *announce reattach vs. new bucket* (see below). So there is **no S3
   teardown code at all**.
4. **DB restore needs no backup.** The Commons create-SQL is already
   reattach-safe: `postgresCommonsCreateSql`/`mysqlCommonsCreateSql` do
   `ALTER ROLE/USER … PASSWORD` (resets creds on the *preserved* data) +
   `CREATE DATABASE … WHERE NOT EXISTS` (keeps data). So `remove` → `init`
   reattaches with a fresh password, data intact. A backup is only relevant as an
   *optional* pre-`--purge` dump (v2, not now).

## Resulting behavior
| Command | Effect |
|---|---|
| `*:remove` (default) | Delete workloads (namespace/resources via each tool's `teardown()`). **Preserve** the Commons DB tenant + S3 buckets + Redis index. Print: "data preserved — re-run `<tool>:init` to restore, or `--purge` to destroy." |
| `*:remove --purge` | The above **plus** drop the Commons DB tenant + release the Redis index (via existing `dropCommonsTenants()`). S3 buckets still preserved. |

Note: the tool-namespace PVC still goes with the namespace delete (it's
derived/cache; authoritative data is in Commons which is preserved). Preserving
PVCs by default is out of scope (would require reworking each `teardown()`).

## Implementation steps
1. **`app/Commands/Tool/AbstractToolRemoveCommand.php`**
   - Signature: replace the `{--keep-data …}` line with
     `{--purge : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.}`
   - `handle()`: change `if (! $this->option('keep-data')) { … dropCommonsTenants … }`
     to `if ($this->option('purge')) { $ok = $this->dropCommonsTenants($kubectl) && $ok; }`.
   - After a successful default (non-purge) remove, print the preserve/restore
     hint. After `--purge`, print that Commons data was destroyed.
   - `teardownWarning()`: default variant should NOT claim data is destroyed;
     add a `--purge` variant that does. (Each subclass overrides copy — keep the
     base default honest; the per-tool overrides like PASSWORDS say "Every stored
     password vault goes with it" — reword to only be true under `--purge`.)
   - `dropCommonsTenants()` already handles DB + Redis; **remove** any S3 bucket
     deletion if present (there is none today — confirm).
2. **`app/Traits/InteractsWithPlex.php` — `allocateStorageBucket()`**
   - Detect whether the bucket already exists before creating. If it does, print
     "Reattached to existing bucket '<name>' (N objects preserved)"; else
     "Created object-storage bucket '<name>'". (SeaweedFS: `weed shell` →
     `s3.bucket.list`, or list the filer path; MinIO: `mc ls`.) Non-fatal if the
     count can't be read — just say "reattached".
3. **Tests**
   - Update every `*RemoveCommandTest` that asserts `--keep-data` behavior.
   - Add: bare `remove` does NOT run the DB drop (assert no `DROP DATABASE`);
     `remove --purge` DOES.
   - `allocateStorageBucket` reattach message when the bucket exists.
4. **Docs:** add an ADR `docs/decisions/0006-remove-is-safe-by-default.md`
   capturing decisions 1–4 above.

## Gotchas
- `dropCommonsTenants()` is guarded by `usesBundledStorage()` (the `--no-plex`
  case) — keep that guard.
- Multi-engine tools (flow, drive, sheets) list BOTH engines in
  `commonsDatabases()`; `--purge` drops both. That's intended (clean slate).
- Pre-release, single cluster: **no migration/back-compat for the old
  `--keep-data` flag** — just replace it (see the "no one-time migration code"
  hard rule).
