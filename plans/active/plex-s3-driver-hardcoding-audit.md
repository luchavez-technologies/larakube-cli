# Plan: Audit and Fix Hardcoded `StorageDriver::SEAWEEDFS` Across S3-Consuming Tools

## Goal Description

`plex:init` lets the operator choose the Commons object-storage backend from
all of `StorageDriver` (SeaweedFS, MinIO, Garage — see
`app/Commands/Plex/PlexInitCommand.php`). Several `{tool}:init` commands that
consume Commons S3 storage ignore that choice and hardcode
`StorageDriver::SEAWEEDFS` directly, instead of detecting which backend is
actually enabled the way `NotesInitCommand`/`SignInitCommand` already do
correctly. On a cluster provisioned with MinIO or Garage instead of
SeaweedFS, these tools would try to allocate a bucket against a Deployment
(`deploy/seaweedfs`) that doesn't exist.

Caught live 2026-08-14 while wiring Twenty CRM's S3 storage — the first draft
of that change hardcoded `StorageDriver::SEAWEEDFS` too, before being
corrected to the pattern below. Same bug, different tool; this plan is to
find and fix every other place it already shipped.

## The Correct Pattern (already used by Notes/Sign — copy this, don't reinvent it)

```php
// The Commons S3 backend is whatever the operator chose at plex:init —
// never assume SeaweedFS.
$spec = $this->getCommonsSpec();
$s3Service = null;
if ($spec !== null) {
    $enabled = $this->enabledCommonsServices($spec);
    if (in_array('seaweedfs', $enabled, true)) {
        $s3Service = 'seaweedfs';
    } elseif (in_array('minio', $enabled, true)) {
        $s3Service = 'minio';
    }
}
$s3Service ??= 'seaweedfs';
if (! $this->ensureCommons([$s3Service])) {
    return 1;
}
$s3Driver = StorageDriver::from($s3Service);

// ... then everywhere the old code passed StorageDriver::SEAWEEDFS literally:
$this->allocateStorageBucket($s3Driver, $bucket);
$this->resolveCommonsS3Endpoints($s3Driver, 'Tool Label');
```

Note this detection list currently only checks `seaweedfs`/`minio` (Garage is
not yet handled by `enabledCommonsServices()`-based detection anywhere in the
codebase, including the reference implementations) — carry that same
limitation forward for consistency, don't silently add Garage support inside
what's meant to be a mechanical fix pass. If Garage support is wanted, that's
a separate, deliberate change to the detection helper itself, touching every
consumer at once.

## Confirmed-Affected Files (hardcode `StorageDriver::SEAWEEDFS` directly)

- [ ] `app/Commands/Chat/ChatInitCommand.php:89` — Matrix/Synapse media repo bucket (`chat-media`). Guarded by `if (! $noPlex)`, so only affects Commons-backed installs (expected — `--no-plex` installs don't touch Commons S3 at all).
- [ ] `app/Commands/Drive/DriveInitCommand.php:80` — oCIS bucket (`drive-ocis`).
- [ ] `app/Commands/Data/DataInitCommand.php:129,141` — Directus engine only (PocketBase has no S3 dependency, embedded SQLite+local disk). Also hardcodes `ensureCommons([..., 'seaweedfs'])` — needs the same dynamic-service-name treatment.

## Already Correct (reference implementations — do not touch, just confirm during audit)

- `app/Commands/Notes/NotesInitCommand.php` (Outline)
- `app/Commands/Sign/SignInitCommand.php` (Documenso)
- `app/Commands/Crm/CrmInitCommand.php` (Twenty CRM — fixed 2026-08-14, this incident)

## Unverified — Audit These Too

Appeared in the broader `readCommonsS3Credentials|allocateStorageBucket|resolveCommonsS3Endpoints` grep but did NOT show up hardcoding `StorageDriver::SEAWEEDFS` literally, which suggests they're already dynamic — but confirm each one actually calls `StorageDriver::from(...)` rather than some other non-hardcoded-but-still-wrong shortcut, and check the same for their `ensureCommons([...])` service-name calls:

- [ ] `app/Commands/Sheet/SheetsInitCommand.php` (Teable — the tool `resolveCommonsS3Endpoints()`'s own docblock says this pattern was lifted from)
- [ ] `app/Commands/Record/RecordInitCommand.php` (SendRec)
- [ ] `app/Commands/Resume/ResumeInitCommand.php`
- [ ] `app/Commands/Design/DesignInitCommand.php` (Penpot)
- [ ] `app/Commands/Mail/MailInitCommand.php` (Stalwart — check whether this one even uses Commons S3 at all, or something unrelated triggered the earlier grep match)
- [ ] `app/Commands/Git/GitInitCommand.php` (Forgejo)
- [ ] `app/Commands/Plex/PlexMigrateCommand.php`, `PlexJoinCommand.php`, `PlexLeaveCommand.php`, `app/Commands/Cloud/CloudExternalizeCommand.php` — these are Plex/cluster-lifecycle commands, not tool consumers; confirm they're driver-agnostic by nature (operating on "whatever Commons has," not assuming a specific backend) rather than needing the same fix.

Not in scope: the various `*NewCommand.php` files (SpringBoot, Wordpress, Nextjs, etc.) and `StorageShowCommand.php` — these list `StorageDriver::SEAWEEDFS->value => StorageDriver::SEAWEEDFS->getLabel()` as one option among a full enum-driven select prompt when scaffolding a **new project's own** storage choice, not consuming an already-provisioned Commons backend. Confirmed by inspection — false positives from the initial grep, not part of this bug class.

## Verification Plan

Per file fixed:
1. `./php vendor/bin/pint`
2. `./php vendor/bin/phpstan analyse --memory-limit=1G`
3. Existing feature test for that tool's `:init` command, plus a new regression test mirroring `CrmInitCommandTest.php`'s `'crm:init detects MinIO rather than assuming SeaweedFS...'` — fake the Commons spec with `minio: enabled` (no `seaweedfs` key), assert the bucket-allocation `exec` targets `deploy/minio` not `deploy/seaweedfs`, and assert nothing SeaweedFS-specific ran.
4. Full suite (`./php vendor/bin/pest`) before considering the pass done.

See `docs/decisions/0015-commons-storage-driver-is-runtime-resolved.md` for
the standing rule this plan exists to bring every consumer into compliance
with.
