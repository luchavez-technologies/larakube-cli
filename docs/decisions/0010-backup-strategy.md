# 0010 — Backups go off-site, encrypted, from a measured inventory

**Status:** accepted
**Date:** 2026-08-07

## Context

The cluster had no backups of any kind: no VolumeSnapshotClass, no CronJobs, no scheduled dumps.
Two plans existed to fix it, and both were unbuildable as written.

`seaweedfs-cluster-backup-system.md` proposed backing up to the cluster's own SeaweedFS. Every
PVC on this node — `postgres-data`, `seaweedfs-data`, `openbao-data`, `chat-synapse-data`,
`drive-ocis-storage`, `forgejo-data`, `prometheus-storage`, `stalwart-data`, `webmail-storage`,
`vaultwarden-storage` — is a `local-path` directory under `/var/lib/rancher/k3s/storage` on the
same `/dev/vda1`. **Backing up to SeaweedFS backs the disk up to itself.** It survives a bad
migration and nothing else.

`external-snapshotter-backup-strategy.md` proposed CSI VolumeSnapshots. The cluster has **zero
CSI drivers**; `local-path-provisioner` is not one. `snapshot:init` would install the CRDs and a
controller, and every VolumeSnapshot would sit Pending forever. It also named five tools to
snapshot, three of which (Directus, Outline, Teable) have no PVC at all — their data is in the
Commons Postgres — while `postgres-data`, which actually holds it, went unmentioned.

Both reached for a mechanism before measuring the data.

## Decision

**Back up from a declared inventory of what cannot be rebuilt, encrypt it, and put it somewhere
that is not this machine.**

Measured, not assumed:

| | Size | |
| --- | --- | --- |
| 13 Postgres databases | ~180 MB | irreplaceable |
| SeaweedFS object store | 49 MB | irreplaceable |
| OCIS files | 23 MB | irreplaceable |
| OpenBao | 2.5 MB | irreplaceable |
| Forgejo repos | 2.0 MB | irreplaceable |
| Vaultwarden | 84 KB | irreplaceable |
| Synapse signing key | 59 B | irreplaceable |
| `prometheus-storage` | **1.1 GB** | **excluded** |
| Synapse `media_store` + `site-packages` | 51 MB | **excluded** |

~260 MB raw. Prometheus alone is four times everything that matters, and losing it costs graphs,
not business data. Synapse's volume is 51 MB of which 59 bytes are irreplaceable: media is
mirrored to object storage and `site-packages` is re-installed on every pod start.

The inventory is an allow-list in `InteractsWithBackup::backupVolumeTargets()`. Databases are
enumerated **live** from `pg_database`, so a newly installed tool is covered without a code
change — the failure mode of a static list is a database silently not backed up.

### Off-site is a hard requirement

`backup:init` refuses any endpoint containing `.svc.cluster.local`, `seaweedfs`, `localhost` or
`127.0.0.1`. Not a warning — a refusal, because it is the mistake both prior plans made and it
produces something that looks exactly like a working backup.

### Cloudflare R2 is the default recommendation

Any S3-compatible endpoint works. R2 fits this workload best: 10 GB free covers ~100 archives at
this size, and **egress is free** — which matters because downloading a backup only ever happens
on a day that is already going badly.

Two provider quirks are handled in `backupAwsEnv()` rather than left as a debugging exercise:
R2 expects region `auto` (now the default), and from aws-cli 2.23 the client sends
`x-amz-checksum-crc32` by default, which R2, B2 and MinIO reject with an opaque signature error.
`AWS_REQUEST_CHECKSUM_CALCULATION=when_required` restores the older behaviour.

`backup:init --create-bucket` provisions the bucket via the Cloudflare API, following
`dns:init`'s token handling: prompted for with the exact scope needed
(**Account · Workers R2 Storage · Edit**), used once, never persisted. The account ID is parsed
out of the endpoint rather than asked for again. Creating a bucket that already exists is
treated as success, since re-running `backup:init` is normal.

**Minting the S3 access keys is deliberately NOT automated.** R2 derives them from an API token,
so creating them programmatically needs a token that can create other tokens — an account-wide
scope that can grant itself anything. That is a bad trade for saving a one-time dashboard visit,
especially on a machine holding client data.

### Encrypted, and the passphrase must leave the machine

Archives are AES-256 encrypted before upload; the destination is a third party's disk. The
passphrase is stored in a Secret **on the cluster the backups exist to survive** — so
`backup:init` prints it once and says plainly that a copy has to be kept elsewhere. Without that,
a total-loss restore recovers an undecryptable file.

### Backup credentials do NOT go into OpenBao

OpenBao runs on the cluster these backups exist to protect, its 2.5 MB volume is on the same
`/dev/vda1`, and `openbao-data` is itself **inside the archive**. Storing the destination or the
passphrase there adds no durability for the only failure that matters, and creates a circular
dependency: the key to the archive lives inside the archive.

For the same reason `backup:restore` accepts `--endpoint`, `--bucket`, `--access-key`,
`--secret-key` and `--passphrase` directly. Reading the destination *from* the cluster only works
for mild failures; the disaster this command exists for is the cluster being gone.

`backup:init` writes those five values to `~/.larakube/backup-recovery.txt` (0600) — off the
cluster, on the operator's machine — and says plainly that a further copy belongs somewhere that
is neither. Not in Vaultwarden: that is on the same box.

### Two ways to run it, and scheduling is its own command

`backup:run` executes from the operator's machine; `backup:schedule` deploys a nightly CronJob.
Both use the same inventory and the same destination Secret, so they cannot disagree.

Neither mounts the six ReadWriteOnce volumes a naive design would need — that pins the job to
one node and couples it to every tool's storage layout. Both stream through `kubectl exec`
instead, so `pg_dump` and `tar` run where the data already lives.

For the CronJob that trade has a price: the job needs **`pods/exec`** in every namespace holding
backed-up data, which is close to root inside those pods. It is bound by RoleBinding to only
those namespaces, and `backup:schedule` prints the grant rather than leaving it to be discovered
in a manifest. `backup:unschedule` removes the ClusterRole and RoleBindings, not just the
CronJob — a standing exec grant with nothing using it is worse than no automation.

The job encrypts in its init container, so the upload container only ever handles the sealed
artifact.

The schedule carries an explicit **`timeZone`**. Kubernetes reads a bare cron expression in the
kube-controller-manager's timezone — UTC on essentially every cluster — so a well-meaning
`17 3 * * *` fires at 11:17 in Manila, 12:17 in Sydney and 20:17 in Los Angeles: business hours,
while `pg_dump` reads every database and tens of megabytes upload from a live cluster. It defaults
to the operator's own zone, `backup:schedule` prints the time in that zone *and* in UTC, and an
unknown zone is refused rather than silently accepted. Requires Kubernetes >= 1.27.

**Scheduling is a separate command, not a flag on `backup:init`.** Configuring a destination
writes one Secret; scheduling puts a recurring workload and a permission grant into the cluster.
Different blast radii, and it must be possible to stop scheduling without touching the
destination. `backup:unschedule` is likewise a real command, and it never removes existing
backups — "stop taking new ones" is not "discard the old ones".

### Partial backups fail loudly

If any dump or archive fails, nothing is uploaded and the command exits non-zero. A partial
backup that reports success is the one discovered during a restore.

### Restore is deliberately half-manual

`backup:restore` defaults to verifying: download, decrypt, unpack, count. Single databases can be
restored live behind a destructive confirmation. **Volume restores are not automated** — each
belongs to a running service that must be stopped first, and a half-restored volume under a live
process is worse than the failure being recovered from. The archive is unpacked and the exact
commands printed instead.

## Consequences

- Roughly $5/month of object storage covers this many times over.
- No storage migration, no CSI driver, no DOKS.
- **Not yet solved: retention/pruning.** Nothing deletes old archives, so storage grows with
  frequency — ~1.6 GB/month nightly, ~6.4 GB/month every six hours, against R2's 10 GB free tier.
  `backup:schedule` projects this at the moment the frequency is chosen rather than leaving it to
  be discovered in a billing email, but the projection is not a substitute for the feature.
- **Not yet solved: an automated restore drill.** A backup nobody has restored is a hypothesis;
  `backup:restore` makes testing cheap but nothing forces it.
- Moving PVCs onto a DigitalOcean Block Storage volume would make droplet loss survivable at the
  infrastructure layer and is complementary — but it is still inside one DO account, so it does
  not replace this.
