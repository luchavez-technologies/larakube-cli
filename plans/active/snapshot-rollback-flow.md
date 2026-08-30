# Plan: `snapshot:rollback` — restore a snapshot onto an existing volume

**Status:** designed, not built. The command exists and refuses with exit 1.
**Why it refuses:** see `SnapshotRollbackCommand`'s class docblock and commit `a5f4ebc`.

## The constraint everything follows from

A bound PVC's `spec.dataSource` is **immutable**. You cannot point an existing
volume at a snapshot, so "rollback" is not an `apply` — it is a replace:

```
scale workload → 0
delete PVC
recreate PVC from snapshot
scale workload → back
```

Every hard part of this plan comes from that: between the delete and the
recreate, the old data is gone and the only thing standing between the user and
an empty volume is a snapshot nobody has verified.

## Non-negotiable preflight

Refuse before touching anything unless all of these hold. The order matters —
each is cheaper than the one after it.

1. **The snapshot exists and `readyToUse` is `true`.** This is the whole ball
   game. `snapshot:create` returns the moment the API accepts the object; the
   CSI driver fills `readyToUse` in afterwards. Restoring from a snapshot that
   never finished destroys the volume and restores nothing. Read
   `.status.readyToUse`, and treat absent as false.
2. **`.status.restoreSize` fits the PVC being replaced.** A restore into a
   smaller volume fails at bind time, after the delete.
3. **The PVC exists and its StorageClass matches the snapshot's.** Cross-class
   restores fail late.
4. **Every workload mounting the PVC is identified.** Find them by scanning
   Deployments/StatefulSets in the namespace for `volumes[].persistentVolumeClaim.claimName`.
   Do not ask the operator to name them: missing one means deleting a PVC still
   mounted, which hangs on the finalizer and leaves everything half-down.
5. **Nothing else mounts it.** More than one workload is a refusal, not a
   prompt — the ordering is theirs to decide, not ours to guess.

## The flow

Record the original replica counts BEFORE scaling. That number is the only way
back if a later step fails, and it is gone once the Deployment is patched.

| # | Step | Failure here means |
| --- | --- | --- |
| 1 | Preflight (above) | Nothing happened. Safe. |
| 2 | Record replicas per workload | Nothing happened. Safe. |
| 3 | Scale each workload to 0, wait for pods to actually terminate | Scale back up. Safe. |
| 4 | Delete the PVC, wait for it to be gone | **Danger begins.** The volume is gone; only the snapshot remains. |
| 5 | Recreate the PVC from the snapshot | Retryable — the snapshot is still there. Do NOT scale up. |
| 6 | Wait for Bound | As step 5. |
| 7 | Scale workloads back to recorded counts | Retryable. |

Step 3 must **wait for termination**, not just patch the replica count. A PVC
delete with a pod still attached blocks on `kubernetes.io/pvc-protection` and
sits there, which reads as a hang rather than a refusal.

Between 4 and 6 the operator has no data. Say so before starting, and print the
snapshot name at each step so a failure mid-flow leaves them holding the one
piece of information recovery needs.

## Interface

```
snapshot:rollback {snapshot} --pvc=<existing-pvc> [--force]
```

`--force` skips the confirmation only. It must never skip preflight — a
readyToUse check that `--force` can turn off is a check that will be off exactly
when it matters.

## Deliberately not doing

- **Backing the old volume up first.** Snapshotting the PVC before deleting it
  doubles the storage and the time, and the operator asked for a rollback
  because the current data is what they want gone. Offer it in the confirmation
  text as a manual step (`snapshot:create` before proceeding), and leave the
  choice with them.
- **Multi-PVC or multi-workload rollback.** One volume, one workload. Ordering
  across several is an application decision.
- **Rolling back a StatefulSet's volumeClaimTemplates.** Those PVCs are managed
  by the controller and recreated on scale-up; the flow above would fight it.
  Detect and refuse.

## Testing

Everything faked through `Process::fake()` — this touches no cluster in tests.
The cases that matter are the refusals, because they are what stands between a
mistake and an empty volume:

- [ ] `readyToUse: false` → refuse, nothing deleted.
- [ ] `readyToUse` absent → refuse (treated as false).
- [ ] restoreSize > PVC size → refuse.
- [ ] two workloads mount the PVC → refuse, both named.
- [ ] StatefulSet volumeClaimTemplate → refuse.
- [ ] happy path → scale-0, delete, apply, scale-back in that ORDER (assert the
      order, not just that each ran).
- [ ] recreate fails → workloads stay at 0 and the snapshot name is printed;
      never scale up over a volume that was not restored.
- [ ] `--force` still runs preflight.
