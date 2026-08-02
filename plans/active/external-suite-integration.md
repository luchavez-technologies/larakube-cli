# External-* Ecosystem Suite & `cloud:scale` Integration Plan

**Status:** Draft / Proposed
**Created:** 2026-07-29
**Updated:** 2026-07-29
**Target Version:** LaraKube CLI v1.1.0

---

## Executive Summary

Currently, scaling a VPS on cloud providers (like DigitalOcean via `cloud:scale`) couples **vCPU, RAM, and Root Disk** into rigid instance sizes (e.g. `s-2vcpu-4gb` = 80GB disk, `s-4vcpu-8gb` = 160GB disk).

If a developer wants 500GB of storage for their database or media files, DigitalOcean's Droplet resize forces them to upgrade to a huge $192/month 16vCPU instance just to get the disk space, even if their CPU and RAM requirements are minimal.

By integrating **`external-provisioner`** (CSI Block Storage) directly into **`cloud:scale`**, LaraKube **decouples Compute Scaling from Storage Scaling**:
- **Compute Scaling (`cloud:scale --size=...`)**: Scales vCPU & RAM independently via OpenTofu.
- **Storage Scaling (`cloud:scale --storage=...`)**: Dynamically provisions or resizes attached DigitalOcean NVMe Block Storage volumes ($0.10/GB) via `external-provisioner` without forcing server upgrades!

---

## Developer Experience (DX) Comparison

```
  [ Old Rigid Cloud Scaling ]                  [ LaraKube Decoupled Scaling ]
  ┌─────────────────────────┐                  ┌─────────────────────────┐
  │ 16 vCPU / 32GB RAM      │                  │ 2 vCPU / 4GB RAM ($24)  │
  │ 500GB Disk              │                  ├─────────────────────────┤
  │ Cost: $192/month        │                  │ 500GB DO Block Volume   │
  └─────────────────────────┘                  │ Cost: $50/month         │
  (Forced to pay for unwanted                  └─────────────────────────┘
   vCPU/RAM just for storage)                  Total: $74/month (Saved $118/mo!)
```

---

## Zero-Data-Loss Migration Path (`local-path` → `do-block-storage`)

When a VPS root disk (160GB) approaches capacity, `larakube storage:migrate` provides a 100% safe, automated pipeline to move database or media PVCs off the root disk and onto a dedicated DigitalOcean Block Storage Volume without data corruption.

```bash
# Migrate a PVC from root disk (local-path) to a dedicated DO Block Volume (do-block-storage)
larakube storage:migrate postgres-data-postgres-0 --to=do-block-storage --size=200Gi
```

### Safe Migration Pipeline:
```
  [ Developer ] ──> larakube storage:migrate postgres-data-postgres-0 --to=do-block-storage
                         │
                         ▼
             1. Clean Workload Pause
             (Scales workload --replicas=0 to freeze dirty DB writes)
                         │
                         ▼
             2. Data Volume Sync (pv-migrate / rsync -aHAX)
             (Launches helper pod; streams data from local-path -> DO Block Volume)
                         │
                         ▼
             3. PVC Re-binding
             (Updates PVC target binding to new DO Block Storage Volume)
                         │
                         ▼
             4. Workload Resume & Verification
             (Scales workload --replicas=1 -> verifies health probe pg_isready)
```

---

## Enhanced CLI Architecture (`cloud:scale` + `external-provisioner`)

```bash
# 1. Scale vCPU and RAM independently (resizes Droplet compute size slug)
larakube cloud:scale prod --size=s-4vcpu-8gb

# 2. Scale Storage independently (attaches / expands DO Block Volume via external-provisioner)
larakube cloud:scale prod --storage=500Gi

# 3. Combine compute and storage scaling in a single command
larakube cloud:scale prod --size=s-4vcpu-8gb --storage=500Gi
```

---

## Technical File Architecture Map

| File Path | Purpose / Description |
| :--- | :--- |
| `app/Commands/Cloud/CloudScaleCommand.php` | Add `--storage=` option. When provided, delegates storage expansion to `external-provisioner`. |
| `app/Commands/Storage/StorageMigrateCommand.php` | Command for zero-data-loss PVC migration between storage classes. |
| `app/Traits/InteractsWithStorageProvisioner.php` | Trait interfacing with CSI `external-provisioner` for volume expansions. |
| `app/Enums/ClusterTool.php` | Add `STORAGE_PROVISIONER` and `SNAPSHOT` tool entries. |
| `app/Commands/Snapshot/SnapshotInitCommand.php` | Deploys snapshot CRDs and controller. |
| `app/Commands/Snapshot/SnapshotCreateCommand.php` | Creates a `VolumeSnapshot` CRD instance for target PVC. |
| `app/Commands/Snapshot/SnapshotCloneCommand.php` | Creates a new PVC sourced from an existing `VolumeSnapshot`. |
| `app/Commands/Snapshot/SnapshotRollbackCommand.php` | Restores a volume snapshot in-place to reset database state. |
| `resources/views/k8s/storage/provisioner.blade.php` | Manifest for CSI `external-provisioner` driver. |
