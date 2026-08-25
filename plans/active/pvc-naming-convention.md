# Extend the instance-suffixed naming convention to PVCs

**Status:** 🔴 NOT STARTED — **HIGH PRIORITY**, pick up next.
**Created:** 2026-08-24
**Related:** `plans/active/openbao-static-role-coverage.md` (the Deployment/Service/Ingress/DB naming convention this extends), the Mail/Stalwart rename and Git/Forgejo server rename (both in recent commit history) — the two live migrations that established the pattern this plan is extending to PVCs.

## Context

The instance-suffixed naming convention (`{tool-slug}-{app-name}-{instance-slug}`) has been rolled out to Deployments, Services, Ingresses, Secrets, and Postgres databases/roles across several tools now (Mail/Stalwart, Meet/LiveKit + its Matrix bridge, Git/Forgejo including its server, Monitor's Loki/Promtail). PVCs were **deliberately excluded from every one of those passes** and left bare (`forgejo-data`, `loki-storage`, `stalwart-data`, `meet-livekit`'s N/A, etc.) — not as an oversight, but because every other resource type in this convention has an **atomic, in-place rename**:

- A Deployment/Service/Ingress can be recreated under a new name pointing at the same underlying pods/config — no data moves.
- `ALTER DATABASE ... RENAME TO` / `ALTER ROLE ... RENAME TO` are atomic, in-place Postgres operations (confirmed safe for both Stalwart's tenant, reverted, and Forgejo's tenant, shipped 2026-08-23/24).

A PVC has no equivalent. Its name is bound to its actual backing volume — there is no `ALTER VOLUME RENAME`. The only way to "rename" a PVC is: create a new PVC under the new name, actually copy the data between them, verify, cut the Deployment over, delete the old one. This is the same shape of problem as the Git S3 buckets (`forgejo-storage`/`forgejo-packages`/`forgejo-lfs`), which also have no atomic rename — except PVCs generally hold higher-stakes data than those buckets did.

**This plan exists because the user explicitly asked for PVCs to be brought into the naming convention** (2026-08-24), while acknowledging the cost/risk tradeoff described above needs to be handled properly, not rushed.

## The mechanism (needs to be built — nothing like this exists yet)

No copy-Job mechanism for PVC-to-PVC data migration exists in this codebase today. `StorageDriver::selfHostedMirrorCommand()`/`commonsToSelfHostedMirrorCommand()` (`app/Enums/StorageDriver.php`) are the closest precedent but operate on **S3 buckets** via `mc mirror`, not block/file volumes — not directly reusable.

For each PVC to be renamed:

1. Create the new PVC (`{old-name}` → `{old-name}-{instance}` or whatever the final convention decides), same `storageClass`/`accessModes`/size as the original.
2. Run a one-shot Kubernetes `Job` that mounts BOTH the old and new PVC and does a real copy (`cp -a` or `rsync -a` — `rsync` preferred if the base image has it, since it's resumable and gives a real progress/verification story for large volumes like `forgejo-data`'s git repos).
3. Verify: compare file counts / total size / (for the highest-stakes volumes) a checksum manifest between old and new before trusting the copy.
4. Scale the Deployment to 0, cut its `volumeClaimName` over to the new PVC, scale back up.
5. Confirm the app is healthy against the new volume.
6. Delete the old PVC only after that's confirmed — not before, and not automatically.

This needs its own generic helper (something like `PvcCopyJob`/a Blade template + a trait method), used the same way `allocateDatabase()`/`allocateStorageBucket()` are used today — built once, applied per-tool.

## Candidate PVCs, roughly by stakes

Not exhaustive — a real audit pass (`grep -rl "kind: PersistentVolumeClaim" resources/views/k8s/`) should re-derive the full list when this is picked up. Rough grouping by what's actually at risk if the copy step is skipped or gets it wrong:

- **Highest stakes — build and prove the mechanism elsewhere first, do these last:** `forgejo-data` (git repos, Actions cache, LFS metadata — the most valuable dataset in this whole convention), `drive/ocis`'s storage (already flagged dangerous — the "encryption key preservation" risk from the mechanical-batch plan applies here too, worth coordinating with that concern rather than treating as a separate problem), `mail/stalwart`'s `stalwart-data` (mailboxes — also carries Stalwart's own persisted-config risk from the Mail rename, so this one may need BOTH the PVC-copy mechanism AND a Stalwart-specific config-reload step).
- **Real but lower-stakes data:** `loki-storage` (retained logs — user already said losing this specific one is fine, so it's actually a candidate for **skip-copy, just recreate empty** rather than exercising the mechanism), `sso/zitadel`'s storage, `webmail/bulwark`, `desk/freescout`, `vault/shared` (Vaultwarden).
- **Good pilot candidates** (low/no real data, good place to first prove the copy-Job mechanism works before trusting it with anything above): whatever's emptiest/newest at the time — check actual PVC usage (`kubectl exec ... du -sh`) rather than assuming from the tool name.

## Suggested approach when this is picked up

1. Build the generic copy-Job mechanism against a disposable/empty PVC first — prove create-new → copy → verify → cutover → delete-old works mechanically before it ever touches real data.
2. Pilot it on one real but low-stakes PVC end-to-end (a candidate from the "lower-stakes" tier above).
3. Only then work through the higher-stakes tier, one at a time, each with its own explicit go-ahead — same incremental, tool-by-tool discipline every prior step of this naming migration has used.
4. `forgejo-data`/`drive-ocis`/`stalwart-data` should each get the same "verify before assuming" treatment the Postgres renames got (confirm what's actually safe about the copy for that specific app — e.g. does Forgejo hold any file locks/handles during the copy that would need the pod stopped first, not just scaled to 0 with a delay).

## Verification

Whatever gets built here needs a real test path before touching prod — the existing `pest` suite can cover the command/trait logic (faking `Process`/`kubectl` like everything else in this codebase), but the copy-Job's actual behavior (does `cp -a`/`rsync` preserve what the app needs — permissions, symlinks, sparse files, whatever Forgejo/oCIS/Stalwart specifically care about) needs a live dry run against a disposable PVC, not just unit coverage.
