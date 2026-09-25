# Backup by declaration — each tool says what of it is worth keeping

## Context

`backup:run` protects a tool's data through two unrelated mechanisms, and
neither is the tool's own decision.

**Volumes are discovered.** `InteractsWithBackup::backupVolumeTargets()` walks
every Deployment in every `larakube-*` namespace, resolves it through
`ClusterTool::forDeployment()`, and archives the components that declare
`backupVolume: true` with `backupPaths`. That part is declarative and works.

**Object storage is not.** There is exactly one explicit entry:

```php
['name' => 'seaweedfs', 'namespace' => 'larakube-plex',
 'deployment' => 'seaweedfs', 'container' => 'seaweedfs', 'paths' => ['/data']]
```

SeaweedFS is archived **whole** — every bucket of every tool, plus whatever
else is in it, as one tar. Three consequences:

- **No tool can opt out.** Chat media, screen recordings and Penpot assets are
  large, rebuildable or disposable, and there is no way to say so. The archive
  grows with the noisiest tool on the cluster.
- **No tool can opt in from elsewhere.** The moment `{tool}:storage` can point
  a bucket at Cloudflare R2 or AWS (see
  `plans/active/tool-backend-choice.md`), that bucket leaves the SeaweedFS blob
  and is silently outside backups entirely.
- **Restore is all-or-nothing.** Recovering one tool's bucket means unpacking
  every tool's.

**Databases are a third story again.** `backup:run` dumps Commons Postgres, but
which tenants belong to which tool is only recoverable by name-matching.

## What the tool should declare

The component already declares its volume. The same component should declare
the other two, so one place answers "what of this tool is worth keeping":

```php
new ClusterToolComponentData(
    key: 'app',
    …,
    backupVolume: true,
    backupPaths: ['/var/lib/ocis'],
    backupBuckets: true,     // this instance's commonsBuckets()
    backupDatabase: true,    // this instance's commonsDatabases()
);
```

Both default to **false**, matching `backupVolume`'s existing convention: a
component opts in, it is never opted in by omission from a list somewhere else.

Names come from `ToolInstance` (`commonsBuckets()`, `commonsDatabases()`), so
nothing new has to be spelled out per tool and a rename carries through
automatically — which is the whole reason the canonical-naming migration had to
come first.

## The per-tool pass

| declares | what `backup:run` does |
|---|---|
| `backupVolume` | tar the declared paths out of the running container (today's behaviour) |
| `backupDatabase` | `pg_dump` that instance's Commons tenants individually |
| `backupBuckets` | sync that instance's buckets, per bucket, not the whole store |

The single `seaweedfs` whole-store entry is **removed** once every tool that
wants its bucket kept declares it. Until then it stays, or the changeover
silently drops coverage — the exact failure this plan exists to prevent.

## Open question — the honest default

Flipping a whole-store archive into per-bucket opt-in means **any tool that
does not declare loses coverage on the day this ships**. Two ways to land it:

1. **Declare-then-remove.** Every tool with a bucket today gets
   `backupBuckets: true`, so behaviour is identical; the whole-store entry goes
   in the same commit. Nothing changes until someone deliberately opts out.
   Safe, and the reason to prefer it.
2. **Opt-in from scratch.** Only tools someone actively chose get backed up.
   Smaller archives immediately, at the cost of silently dropping whatever
   nobody got around to declaring.

**(1) is recommended** — an omission should not be able to lose data, and the
first pass over ~20 vendors is exactly where an omission is likeliest.

## `backup:init` and non-R2 providers

Already supported, contrary to how the command reads. `--endpoint`,
`--bucket`, `--access-key`, `--secret-key` are plain S3, and the prompt's own
hint names Backblaze B2 and DO Spaces. Only `--create-bucket` /
`--cloudflare-token` is R2-specific, and it refuses other endpoints clearly
("Bucket creation is only supported for Cloudflare R2").

One real papercut: `--region` defaults to `auto`, which R2 requires and **AWS
S3 rejects**. An AWS destination needs `--region=us-east-1` explicitly, and
nothing says so. Worth either detecting an `amazonaws.com` endpoint and
defaulting accordingly, or naming it in the prompt hint.

## Files

- `app/Data/ClusterToolComponentData.php` — `backupBuckets`, `backupDatabase`,
  both defaulting false, carried through `renamed()`.
- `app/Traits/InteractsWithBackup.php` — `backupVolumeTargets()` gains bucket
  and database targets; the explicit `seaweedfs` entry goes once the per-tool
  declarations cover it.
- `app/Commands/Backup/BackupRunCommand.php` — per-bucket sync and per-tenant
  dump.
- `app/Commands/Backup/BackupRestoreCommand.php` — restore one tool's bucket or
  database without unpacking the whole store. **This is the half that must not
  be skipped**: a backup format nothing can restore selectively is the current
  problem wearing a different shape.
- The ~20 vendor classes that declare a bucket or a Commons database.

## Sequencing

After the canonical-naming migration, alongside or after
`plans/active/tool-backend-choice.md` — external S3 is what makes the
whole-store assumption fail outright, and both plans touch
`ClusterToolComponentData`.

Related: `plans/active/backup-per-item-objects.md`,
`plans/active/backup-volume-discovery.md`,
`plans/active/seaweedfs-cluster-backup-system.md`.
