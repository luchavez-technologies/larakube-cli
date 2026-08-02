# Plan: Multi-Node & Managed Storage Strategy (DOKS / VPS / Multi-Node HA)

**Status:** Active Plan Document
**Created:** 2026-07-27
**Updated:** 2026-07-29

---

## 1. Executive Problem Statement

On a **single-node VPS**, all LaraKube pods (`web`, `horizon`, `scheduler`, `reverb`, `postgres`, `redis`) run on the same server. `local-path` (`ReadWriteOnce`) allows multiple pods on the same node to mount shared storage seamlessly.

On **multi-node clusters (DOKS / EKS / GKE)**, pods spread across multiple worker nodes. Standard cloud block storage (DigitalOcean Block Storage, AWS EBS, GCP PD) is **`ReadWriteOnce` (RWO)** — it cannot be mounted simultaneously by pods on different nodes. If a second web pod lands on Node B while Node A holds the volume, the pod stays stuck in `Pending` forever.

---

## 2. The Two First-Class Multi-Node Paths

LaraKube offers **two first-class paths** for multi-node storage:

### Path A: Stateless 12-Factor App (Recommended for Greenfield & Scaled Apps)
App pods share **no filesystem on disk**. State is completely externalized:

| State Domain | Single-Node Storage | Multi-Node Stateless Target |
| :--- | :--- | :--- |
| **Uploads (`storage/app`)** | Shared PVC | **S3 Object Storage** (SeaweedFS / MinIO / DigitalOcean Spaces) |
| **Cache & Sessions** | File (`storage/framework`) | **Redis** (Plex Redis / Dedicated Redis) |
| **Compiled Assets** | Shared PVC | **Baked into Container Image** at build (`artisan optimize`) |
| **System Logs** | Local files | **stdout/stderr** (Streamed to Vector / Fluentbit) |

### Path B: In-Cluster Shared NFS (`RWX` via `cloud:init:nfs`)
For existing Laravel applications that require a shared physical `/var/www/storage/app/public` folder across multiple web nodes with **zero app code changes**:
- `larakube cloud:init:nfs` deploys an in-cluster NFS storage driver (`larakube-nfs` / `nfs-subdir-external-provisioner`).
- All web worker pods across Node A, Node B, and Node C mount the shared NFS storage class concurrently with real-time POSIX file locks.

---

## 3. High Availability Database Failover (`external-provisioner`)

For single-pod databases (Postgres, MySQL, OpenBao, Redis):
- Databases use `do-block-storage` (`ReadWriteOnce`).
- **Automatic Failover**: If Worker Node A fails, Kubernetes reschedules the Postgres pod onto Worker Node B.
- `external-provisioner` automatically detaches the DigitalOcean Block Storage volume from Node A and attaches it to Node B in **~10 seconds**. The database starts up on Node B with **zero data loss**.

---

## 4. Zero-Data-Loss Volume Migration (`storage:migrate`)

When migrating a database or workload from local node storage (`local-path`) to dedicated cloud block storage (`do-block-storage`):

```bash
larakube storage:migrate postgres-data-postgres-0 --to=do-block-storage --size=200Gi
```

### Execution Flow:
1. **Workload Pause**: Scales workload `--replicas=0` to freeze active writes.
2. **Data Sync**: Spawns migration helper container streaming data from `local-path` to `do-block-storage` via high-speed `rsync -aHAX`.
3. **PVC Re-binding**: Binds target claim to the new DO Block Volume.
4. **Workload Resume**: Scales `--replicas=1` and verifies health probe (`pg_isready`).

---

## Technical File Architecture Map

| File Path | Description / Purpose |
| :--- | :--- |
| `app/Enums/StorageDriver.php` | Storage drivers (`LOCAL_PATH`, `DO_BLOCK_STORAGE`, `LARAKUBE_NFS`, `S3`). |
| `app/Commands/Cloud/CloudInitNfsCommand.php` | Command for deploying in-cluster NFS provisioner (`cloud:init:nfs`). |
| `app/Commands/Storage/StorageMigrateCommand.php` | Command for zero-data-loss volume migration between storage classes. |
| `resources/views/k8s/storage/nfs.blade.php` | Manifest template for `larakube-nfs` driver. |
| `resources/views/k8s/storage/provisioner.blade.php` | Manifest template for CSI `external-provisioner` driver. |
