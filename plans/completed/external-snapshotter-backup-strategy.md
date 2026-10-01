# Plan: External Snapshotter & Zero-Data-Loss Backup Strategy

**Status:** 🗄️ SUPERSEDED — verified 2026-08-08: the shipped answer is `backup:init`/`run`/`schedule`/`list`/`restore` (off-site, encrypted, to Cloudflare R2), with `larakube-backup` running nightly on `larakube-159.89.205.239`. external-snapshotter was investigated and rejected — this node's `local-path-provisioner` is not a CSI driver and has no VolumeSnapshotClass. See ADR 0010.

---

## 🎯 Objective

Ensure zero data loss across all dynamic company tools (`data` Directus, `notes` Outline, `sheet` Teable, `chat` Synapse, `drive` OCIS) by utilizing LaraKube's **`external-snapshotter`** (CSI VolumeSnapshots) suite, instant rollbacks, and secrets/database export automations.

---

## 🛡 Backup & Protection Suite

1. **`larakube snapshot:init`**: Deploys Kubernetes `external-snapshotter` CRDs (`snapshot.storage.k8s.io`).
2. **`larakube snapshot:create {pvc}`**: Creates instant point-in-time snapshot before edits.
3. **`larakube snapshot:rollback`**: Instantly restores PVC back to snapshot.
4. **`larakube snapshot:clone`**: Clones snapshot into a safe test environment.
5. **`larakube secrets:export`**: Backs up all cluster credentials to local encrypted storage.
6. **`larakube context:backup`**: Backs up kubeconfig context snapshots.

---

## ✅ Operations Checklist

- [x] `external-snapshotter` integration in `SnapshotInitCommand`
- [x] Interactive `snapshot:list`, `snapshot:rollback`, `snapshot:clone`
- [x] Mandatory safety backup in `plex:leave` before database drop
- [ ] Schedule automated cron/timer for daily volume snapshots
