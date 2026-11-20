# Supabase in `backup:run` / `backup:restore`

**Status:** PLANNED, not started. Not done until a restore is proven on a scratch Supabase instance.

## What is already covered

- **Storage files on the Commons (default install):** they live in the Commons SeaweedFS bucket, and `backup:run` already archives SeaweedFS's `/data` whole (`InteractsWithBackup::backupVolumeTargets()`).

## Gaps

1. **The dedicated Postgres** (`supabase-db-<instance>` StatefulSet, ADR 0032). `backup:run` only dumps databases on the Commons Postgres (`backupDatabases()`), so every Supabase table, Auth user and Storage object row is unprotected.
2. **The Edge Functions volume** (`supabase-functions-<instance>`, `/home/deno/functions`): the user's own functions.
3. **The `--no-plex` Storage volume** (`supabase-storage-<instance>`, `/var/lib/storage`).

## Why the generic volume flag can't be used

`ClusterToolComponentData::backupVolume` archives with `tar czf -` inside the container:

- `supabase/edge-runtime` has GNU tar but **no gzip**, so the functions archive would fail every night.
- The Storage volume exists only with `--no-plex`, so flagging the `storage` component would make every Commons-mode install report a failed archive.

Both need a Supabase-aware target: stream `tar cf -` (no `z`) out and gzip on the operator's side, and include Storage only when its PVC exists (`SupabaseRemoveCommand::usesBundledStorage()` already checks this).

## Database dump: follow upstream's 3-part method

Supabase's own guidance for moving a project is roles, then schema, then data, rather than one `pg_dumpall`. The image's init scripts already create the `supabase_*` roles and internal schemas, so a plain restore collides with them.

- `pg_dumpall --roles-only` (filtered to the roles users created)
- `pg_dump --schema-only` excluding the schemas the image owns
- `pg_dump --data-only --use-copy` for `public`, `auth`, `storage` and the like

Run each one as `supabase_admin` over TCP, as `enableVectorExtension()` does, and stream it out through `kubectl exec`, as `backup:run` does today.

## Restore: the part to prove

- Restore into a **freshly initialised** instance: run `supabase:init`, then load the roles, schema and data.
- Watch for the trap from the Commons restore: no `DROP`s plus objects owned by the wrong role. Every object must come back owned by its original role.
- Archive names must carry the instance (`supabase-db-<instance>`), so one instance can be restored alone.

## Proof checklist

1. Spin up a scratch Supabase. Create a table with RLS, an Auth user, a Storage object and a function.
2. Run `backup:run`, then destroy the instance with `--purge`.
3. Re-install, then run `backup:restore`.
4. Check that:
   - the row is readable through REST with the publishable key;
   - the user can sign in;
   - the object can be downloaded;
   - the function answers.
5. Update ADR 0032's "Backup boundary" consequence once it passes.
