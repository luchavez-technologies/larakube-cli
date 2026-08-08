# Plan: back up per item, not as one archive

**Status:** ✅ BUILT 2026-08-08, commit `5c9ac55`. Gate green (1533 tests, pint, phpstan).
NOT yet exercised against a real destination — see "End-to-end test" at the bottom. The
existing single-archive backups on R2 are unreadable by the new code by design (no migration
path, per the no-one-time-migration rule); `backup:prune --apply` clears them.

## Why

The drill made the ratio concrete: restoring the 39.2KB `forgejo` database required
downloading and decrypting the **entire 54.8MB archive**. That is 1400× overhead, and it is
structurally lopsided — `seaweedfs` alone is 49.3MB of the 55MB, and it is the item *least*
likely to need a targeted restore.

Measured 2026-08-08 on `larakube-159.89.205.239`:

| Databases (12) | | Volumes (7) | |
|---|---|---|---|
| chat_matrix | 26 MB | seaweedfs | 49.3 M |
| windmill | 20 MB | drive-ocis | 23.1 M |
| zitadel | 18 MB | openbao | 2.5 M |
| forgejo | 17 MB | forgejo | 2.0 M |
| teable | 15 MB | vaultwarden | 84 K |
| sign_documenso | 14 MB | stalwart | 4.0 K |
| outline / stalwart | 13 / 12 MB | synapse-identity | 197 B |
| 5 others | 8–9 MB each | | |

(Uncompressed. The whole archive compresses to ~55MB.)

The nightly job already takes **~64 minutes** (started 19:17 UTC, `lastSuccessfulTime`
20:21). Both numbers grow with the object store, and every single-item restore pays for all
of it.

## The design

One object per item plus a manifest, under a per-run prefix:

```
larakube/2026-08-08-031700/db-forgejo.sql.gz.enc
larakube/2026-08-08-031700/vol-seaweedfs.tar.gz.enc
larakube/2026-08-08-031700/manifest.json          ← written LAST
```

`backup:restore` reads **only the manifest**, builds the multiselect from it — so the picker
appears before any download — then fetches just what was selected.

### The manifest is a commit marker, not a convenience

The single genuine property the monolith has today is atomicity at the destination: the
whole archive lands or nothing does, so a half-backup is not observable. Nineteen separate
objects can interleave with a crash.

Writing `manifest.json` last restores that property. A prefix without a manifest is an
incomplete run: `backup:list` must hide it and `backup:restore` must refuse it. This is the
whole reason the manifest exists — do not "optimise" it into being written first.

```json
{
  "version": 1,
  "taken_at": "2026-08-08T03:17:00Z",
  "engine": "postgres",
  "items": [
    {"kind": "database", "name": "forgejo", "object": "db-forgejo.sql.gz.enc", "bytes": 40140},
    {"kind": "volume", "name": "seaweedfs", "object": "vol-seaweedfs.tar.gz.enc", "bytes": 51707904}
  ]
}
```

### An argument NOT to make

"The monolith is a consistent point-in-time snapshot." It is not. The job dumps sequentially
over ~64 minutes, so the last volume is already an hour newer than the first database.
Splitting loses nothing that exists today. Do not reintroduce this as a reason to keep it.

## Scope

| File | Change |
|---|---|
| `app/Commands/Backup/BackupRunCommand.php` (185) | encrypt + upload per item; write the manifest last |
| `app/Commands/Backup/BackupListCommand.php` (87) | list manifests, not objects; skip manifest-less prefixes |
| `app/Commands/Backup/BackupRestoreCommand.php` (508) | fetch manifest → multiselect → download only selected |
| `app/Traits/InteractsWithBackup.php` (333) | manifest read/write helpers; `latestObject()` becomes `latestManifest()` |
| `resources/views/k8s/backup/cronjob.blade.php` (216) | the in-cluster job does the same, per item |

The 3-stage CronJob (dump → `alpine/openssl` encrypt → `amazon/aws-cli` upload) becomes a
loop over items. Keep the stage split — the images are pinned for a reason and the encrypt
stage deliberately does not carry a database client.

### Verification must survive

Today "verify" means download-and-decrypt everything, which is what makes the drill real. If
restore only fetches what you pick, that property is lost by default. Add `--deep`, which
pulls and decrypts every item in the manifest and reports per-item results. **The drill is
`--deep`.** Without it this change quietly removes the only proof the backups work.

### No dual-format support

Per the no-one-time-migration rule: new format only. The three existing archives
(`2026-08-07-182208`, `-200328`, `-202145`) are read by nothing afterwards — delete them by
hand or let them age out. Do not write a reader that handles both.

## Tests

Extend `tests/Feature/BackupCommandTest.php` (46 tests, all `Process::fake()`):

- a prefix without `manifest.json` is invisible to `backup:list` and refused by restore
- the manifest is uploaded **after** every item object (assert call order)
- restoring one database downloads exactly one object, not the whole prefix
- `--deep` downloads every item in the manifest
- a manifest naming an item that is not at the destination fails loudly rather than
  restoring a subset silently
- the CronJob template loops all 19 items and still pins every image

## Verification

1. `./php vendor/bin/pint && ./php vendor/bin/phpstan && ./php vendor/bin/pest`, then **you**
   run `./build`.
2. `larakube backup:run production` → 19 objects + manifest at the new prefix.
3. `larakube backup:restore production --deep` → every item verifies.
4. `larakube backup:restore production --database=forgejo` → confirm from the output that it
   downloaded ~40KB, not 55MB. That number is the whole point of this plan.
5. Kill the job mid-run and confirm the partial prefix is invisible to `backup:list`.
6. Append a dated section to `plans/testing-checklist.md`.

## Open questions

- **Retention interacts with this.** There is still no `backup:prune`, and pruning per-prefix
  is much easier than per-archive. Worth building in the same pass — at ~55MB/night against
  R2's 10GB free tier that is roughly six months to overage.
- **Grafana's PVC is not backed up.** Out of scope here; noted so it is not lost. Dashboards
  only.
- **Per-item parallel upload** would cut the 64-minute runtime, but adds failure modes to a
  command whose whole value is being trustworthy. Sequential first; measure before optimising.

## Session state (2026-08-08, for whoever picks this up)

Shipped today, all committed, all green (1525 tests, pint, phpstan):

- `5e4bd82` Postgres restore preamble — `DROP SCHEMA` + `SET ROLE` so an archive replays into
  a populated database and tables stay owned by the tenant role
- `73a87a9` volume restore via a throwaway alpine pod; multiselect after unpack; work-dir
  cleanup
- `68b7abb` restore made engine-aware; `--dry-run` honoured
- `5741bc1` CA test no longer leaves private keys in temp
- `860f8e4` MongoDB is a Deployment over a standalone PVC

**Not yet done:** `./build` had not been run after `5e4bd82`, so the binary predates the
Postgres preamble fix.

**Live cluster caveat:** `forgejo`'s volume was restored from the 2026-08-07-232930 archive
while its database was left at current state — the drill failed between the two. The halves
are mismatched until a database restore completes. Verify the Forgejo UI lists repositories
correctly before trusting it.

**Proven working:** volume restore (stop → mount claim in helper pod → untar → restart) ran
end to end against `forgejo` and the helper pod cleaned up. The claim must be mounted at the
same path the real pod uses — the archive's leading component is the mount point itself.

---

## End-to-end test (2026-08-08) — run this before trusting it

Nothing below has been run. The gate is green and the restore paths it replaces were proven
live on 2026-08-07, but the per-item layout itself has never touched R2.

```bash
cd cli && ./php vendor/bin/pint && ./build
cd ../luchtech
```

**1. Take one in the new layout.**
```bash
larakube backup:run production
```
Expect `Stored: s3://luchtech-backups/larakube/<stamp>/` and a count of objects, not one
archive. It uploads 19 objects then the manifest.

**2. The old archives should now be invisible.**
```bash
larakube backup:list production
```
Expect exactly ONE row — the run from step 1. The three single-archive backups
(`2026-08-07-182208`, `-200328`, `-202145`) are not in the new layout and must not appear.
If they do, `listBackupRuns()`'s prefix regex is wrong.

**3. The drill.**
```bash
larakube backup:restore production --deep
```
Expect `Every item downloads and decrypts — 19 objects`. This is the replacement for the old
download-everything verification; if it passes, the archive is readable end to end.

**4. The point of the whole change.**
```bash
larakube backup:restore production --database=forgejo
```
Watch the download line: it must read ~40KB, **not 55MB**. That single number is what this
plan was for. Then re-verify ownership, which is the failure that looks like success:
```bash
kubectl --context=larakube-159.89.205.239 exec deploy/postgres -n larakube-plex -c postgres -- \
  psql -U postgres -d forgejo -t -A -c \
  "select tableowner, count(*) from pg_tables where schemaname='public' group by tableowner"
```
Expect `forgejo|130`. Anything owned by `postgres` means the SET ROLE preamble regressed.

**5. Partial-run safety.** Start a `backup:run` and interrupt it mid-upload (Ctrl-C after a
few objects). Then `larakube backup:list production` — the interrupted run must NOT be listed,
and the command should warn that an incomplete backup exists.

**6. Prune, showing first.**
```bash
larakube backup:prune production
```
Expect a table marking every row keep/delete, the three old single-archive entries flagged
`no manifest`, and `Nothing was deleted`. Only then:
```bash
larakube backup:prune production --apply
```

**7. Re-schedule — REQUIRED.** `cronjob.blade.php` was converted in a follow-up commit, but
the CronJob already deployed on the cluster still carries the OLD bundling script. It is a
baked-in manifest, not a mounted file, so it does not update itself:

```bash
larakube backup:schedule production
```

Until you do this, tonight's scheduled run writes the old single-archive layout, which
`backup:list` will not show and `backup:restore` cannot read — backups that exist and are
invisible. Confirm afterwards with `kubectl get cronjob larakube-backup -n larakube-shared
-o yaml | grep -c manifest.json` (expect a non-zero count).
