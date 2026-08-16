# 0015 — A tool's Commons S3 backend is resolved at runtime from `plex-commons`, never hardcoded to a specific `StorageDriver`

**Status:** Accepted (2026-08-14)

## Context

`StorageDriver` (`app/Enums/StorageDriver.php`) has three cases — `SEAWEEDFS`,
`MINIO`, `GARAGE` — and `plex:init` (`app/Commands/Plex/PlexInitCommand.php`)
lets the operator choose which one backs a given cluster's Commons. Which
driver is actually running is recorded in the `plex-commons` ConfigMap
(`InteractsWithPlex::getCommonsSpec()`), keyed by service name
(`seaweedfs`/`minio`/`garage`), each with its own `enabled` flag.

Several `{tool}:init` commands that provision a Commons S3 bucket for a
tool's file storage never read that spec — they call
`$this->allocateStorageBucket(StorageDriver::SEAWEEDFS, $bucket)` and
`$this->ensureCommons([..., 'seaweedfs'])` with the driver written directly
into the source, as if SeaweedFS were the only backend that could ever
exist. On a cluster provisioned with MinIO or Garage instead, this points
`kubectl exec` at a `deploy/seaweedfs` that was never created — the bucket
allocation fails outright, or (worse, if `ensureCommons()`'s own check is
lenient) silently degrades to `STORAGE_TYPE=local` and starts losing
uploaded files on every pod restart.

Caught live 2026-08-14: the first draft of Twenty CRM's S3 wiring
(`CrmInitCommand.php`) made exactly this mistake — copied from
`DataInitCommand.php`'s Directus wiring, which has the same bug. `NotesInitCommand.php`
(Outline) and `SignInitCommand.php` (Documenso) already do this correctly;
neither invented anything new, they just read `plex-commons` instead of
assuming.

## Decision

1. Any `{tool}:init` command that provisions Commons S3 storage MUST resolve
   the active driver from `getCommonsSpec()`/`enabledCommonsServices()` at
   runtime — never reference `StorageDriver::SEAWEEDFS` (or any other
   specific case) as a literal when calling `allocateStorageBucket()`,
   `resolveCommonsS3Endpoints()`, or `ensureCommons()`. The canonical
   detection snippet (copy verbatim, don't reinvent):

   ```php
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
   ```

2. This detection intentionally checks only `seaweedfs`/`minio` today —
   `Garage` is not yet wired into `enabledCommonsServices()`-based detection
   anywhere, including the reference implementations. Adding Garage support
   is a deliberate, separate change to the shared detection snippet (and
   every consumer at once), not something a single tool's `:init` should do
   unilaterally.
3. `StorageDriver::SEAWEEDFS` as a literal is fine — expected, even — in
   contexts that are genuinely SeaweedFS-specific or that enumerate every
   `StorageDriver` case on purpose: a `*:new` project scaffold's own select
   prompt for which driver *that project* should provision
   (`SpringBootNewCommand.php` and siblings), `StorageShowCommand.php`, or
   `PlexInitCommand.php` itself (the one place that's deciding the driver,
   not consuming an already-decided one). The rule in #1 is specifically
   about tools *consuming* an existing Commons bucket.

## Consequences

- A cluster provisioned with MinIO or Garage gets a working Twenty CRM /
  Directus / Matrix / oCIS install on the first `:init` run, same as
  SeaweedFS — no silent fallback to ephemeral local storage, no failed
  bucket-allocation `kubectl exec` against a Deployment that was never
  created.
- Every consumer's regression test can pin this the same way:
  fake `plex-commons` with `minio: enabled` and *no* `seaweedfs` key, then
  assert the bucket-allocation command targets `deploy/minio`, never
  `deploy/seaweedfs`. See `CrmInitCommandTest.php`'s
  `'crm:init detects MinIO rather than assuming SeaweedFS...'` for the
  exact shape.
- Known-affected files as of this ADR (tracked to completion in
  `plans/active/plex-s3-driver-hardcoding-audit.md`): `ChatInitCommand.php`,
  `DriveInitCommand.php`, `DataInitCommand.php` (Directus engine only).
  `CrmInitCommand.php` was fixed same-day, before ever shipping hardcoded.
