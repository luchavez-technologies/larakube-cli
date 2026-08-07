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

### Encrypted, and the passphrase must leave the machine

Archives are AES-256 encrypted before upload; the destination is a third party's disk. The
passphrase is stored in a Secret **on the cluster the backups exist to survive** — so
`backup:init` prints it once and says plainly that a copy has to be kept elsewhere. Without that,
a total-loss restore recovers an undecryptable file.

### Run from the operator's machine, not a CronJob

A Pod would have to mount six ReadWriteOnce volumes owned by six different tools — workable only
while everything is on one node, and it couples the backup to every tool's storage layout.
Streaming through `kubectl exec` needs no mounts, no privileged pod, and no image carrying
`pg_dump`, `tar` and an S3 client simultaneously.

The cost is honest and stated: **backups only run when someone runs them.** Scheduling is a
follow-up, and until it exists this is a manual control.

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
- **Not yet solved:** scheduling, retention/pruning, and a periodic automated restore drill. A
  backup nobody has restored is a hypothesis; `backup:restore` makes testing cheap but nothing
  yet forces it.
- Moving PVCs onto a DigitalOcean Block Storage volume would make droplet loss survivable at the
  infrastructure layer and is complementary — but it is still inside one DO account, so it does
  not replace this.
