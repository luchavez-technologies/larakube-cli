# Adversarial Architecture Review Prompt — Portable Backups & Cloud Jump

> **Status: APPROVED FOR EXECUTION (Phase 0+) at Pass 5.** Do not repeat the earlier reviews or sign-offs. Before implementing, read the binding conditions V1-V6 in [Final Sign-Off Verdict (Pass 5)](#final-sign-off-verdict-pass-5) at the bottom of this file.

You are doing a meticulous, adversarial architecture review of a high-stakes plan before implementation begins. **Do NOT edit any files.**

Start by reading the plan in full:
- `cli/plans/active/portable-backups-and-cloud-jump.md`

Then verify every assumption in the plan against the actual codebase — do not trust the plan's claims without checking the source files and ADRs:
- **ADRs:** `cli/docs/decisions/0010-backup-strategy.md`, `0012-cluster-tool-registry-redesign.md`, `0014-cluster-tools-vs-project-apps-deploy-path.md`, `0015-commons-storage-driver-is-runtime-resolved.md`, `0016-openbao-auto-unseal-everywhere.md`, `0017-db-password-defers-to-openbao-static-role.md`, `0021-cluster-tool-resource-naming.md`, `0032-supabase-dedicated-postgres.md`, `0035-commons-postgres-ships-pgvector.md`, and `desktop/docs/decisions/0001-drive-the-cli-never-embed-it.md`
- **Backup & migration code:** `cli/app/Traits/InteractsWithBackup.php`, `cli/app/Commands/Backup/*`, `cli/resources/views/k8s/backup/cronjob.blade.php`, `cli/app/Commands/Cloud/CloudMigrateCommand.php`, `cli/app/Traits/QuiescesAppDeployments.php`
- **Secrets, Plex, Tools, ExternalDNS, Desktop contract:** `cli/app/Commands/Secrets/SecretsExportCommand.php`, `cli/app/Commands/Secrets/SecretsImportCommand.php`, `cli/app/Commands/Plex/PlexExportCommand.php`, `cli/app/Commands/Plex/PlexInitCommand.php`, `cli/app/Commands/Plex/PlexJoinCommand.php`, `cli/app/Traits/InteractsWithToolRegistry.php`, `cli/app/Enums/ClusterTool.php`, `cli/app/Commands/ExternalDns/*`, `cli/resources/views/k8s/dns/*`, `desktop/app/Http/Controllers/BackupController.php`, `desktop/app/Services/LaraKube/ClusterStatus.php`

Stress-test the plan across these 9 failure domains and try to break it:

1. **Secrets, OpenBao, ESO & DB Passwords (ADR 0016 & 0017):**
   - If we restore labelled K8s Secrets → init a fresh OpenBao → `secrets:import` KV → `plex:init` → restore DB dumps → `tool:init`, what breaks?
   - Check OpenBao database engine / static roles (`VaultDynamicSecret` / `ExternalSecret`), Postgres `pg_dump` (does it dump roles/grants or only DB contents? What about Supabase dedicated Postgres in ADR 0032?), and whether ESO reconciliation could overwrite restored Secrets or desync passwords.
   - Check whether `secrets:export` / `secrets:import` actually round-trips everything OpenBao holds today (KV mounts, policies, auth roles, static roles).

2. **Cluster Tool Replay Fidelity (`*:init` + `*:wire` + customizations):**
   - Does replaying registry + `luchtech.dev/install-options` actually recreate a cluster app faithfully?
   - What about post-init state that isn't in `install-options`: `sso:wire` (OIDC clients/secrets in Zitadel), `mail:wire` (SMTP creds), `secrets:wire`, `tool:alias`, `tool:env:set`, `tool:resources`, `tool:proxy`, `tool:pause` (ADR 0030)?
   - Are there tools with special storage/DB architectures (e.g., Supabase dedicated Postgres, PocketBase SQLite, Synapse signing key, Stalwart mail store, Vaultwarden, OpenBao) that break under the proposed restore ordering?

3. **Logical Per-Bucket S3 Capture & Restore:**
   - Check how SeaweedFS, MinIO, and Garage are actually deployed and credentialed in `plex:init` / `StorageDriver`. Can `backup:run` (via port-forward) and the in-cluster CronJob both list and sync buckets cleanly across all 3 drivers?
   - What about bucket ACLs/public-read policies/CORS (e.g., public uploads), empty buckets, large buckets (disk/ephemeral-storage exhaustion during `tar` + `openssl enc`), and encryption streaming vs buffering?
   - Is dropping the raw SeaweedFS `/data` tar genuinely safe, or does SeaweedFS hold metadata/IAM/policies not captured by `aws s3 sync`?

4. **Cluster-Side Project Discovery (Strict CLI Isolation):**
   - The plan drops `~/.larakube/projects.json` and discovers project namespaces live from cluster labels (`luchtech.dev/project`, `luchtech.dev/env`). Check whether project deployments/namespaces actually carry those labels today across ALL supported frameworks (Laravel, WordPress, Next.js, Django, Spring Boot, etc.).
   - Can `CapturesProjectData` accurately identify self-hosted DBs, SQLite files, and local storage paths from the cluster alone without `.larakube.json`? What about custom env vars / `dotenv:push` secrets?

5. **Merge Guards & Multi-Tenant Collisions:**
   - When jumping a single project or cluster app into an existing cluster that already has a Commons/tools, what collisions or hidden couplings exist beyond DB engine, pgvector, and host/tenant ID (e.g., Redis index table in `plex-commons` registry, SSO/Mail cross-references, `larakube-restore-*` markers, Traefik middlewares)?

6. **Two-Phase DNS Cutover & ExternalDNS:**
   - Inspect `external-dns:init` and `resources/views/k8s/dns/*` to check exact `txt-owner-id`, `--policy=sync`, and provider flags.
   - Trace what happens if source ExternalDNS is paused and destination ExternalDNS reuses `txt-owner-id` vs rewrites TXT ownership records — especially during a **partial** jump (one project or one tool, NOT `--all`) where the source cluster STILL owns other records in the same zone!

7. **Quiesce, Maintenance, Rollback & Concurrency:**
   - What happens on non-Laravel apps that don't have `php artisan down`?
   - What if a nightly backup CronJob fires while a jump is quiesced or restoring?
   - Trace partial-failure recovery at every step of `cloud:migrate` and `backup:restore`: is `--rollback` and re-running idempotent and non-destructive?

8. **Bundle Format v2, Encryption & Desktop Compatibility:**
   - Does changing `backup:status --json`, `backup:list --json`, `backup:run --json`, `backup:prune --json`, or `manifest.json` break the existing Desktop app (`BackupController.php`, `ClusterStatus.php`) before the Desktop follow-up ships?
   - Any security issues with `manifest.json` (unencrypted) leaking project names, hosts, or external service topology, or with `openssl enc -aes-256-cbc` (unauthenticated encryption vs tampering, even with `sha256` in an unencrypted manifest)?

9. **Phasing & Irreversible Decisions:**
   - Which decisions in Phase 1–2 are hardest to change later (bucket prefix layout, manifest v2 schema, `cluster_id` storage, restore marker format, CLI flag names), and should any of them be adjusted now before writing code?

Deliver your review as:
- **Blockers (must redesign before Phase 1)** — with exact file paths/line numbers and concrete proposed fixes.
- **High-risk gaps (must address in the plan before coding)** — with concrete amendments.
- **Medium / Low observations & edge cases.**
- **Verdict:** Ready to execute after amendments, or needs a structural rethink on specific parts.

---

# Review Findings and Handoff

## Instructions for Antigravity

1. Amend `cli/plans/active/portable-backups-and-cloud-jump.md` so that every finding below (B1-B10, H1-H13, M1-M9) is resolved in the plan. Add a short "Review resolution" table mapping each ID to the plan section that addresses it.
2. **Change the plan only. Do not implement code, change ADRs, or touch a cluster in this pass.** Stop after the plan is amended so the user can review it.
3. Re-check each cited line before relying on it, because the code may have moved since this review.
4. If a proposed fix is wrong or a better one exists, keep the finding's requirement, record the alternative and the evidence for it, and flag it for the user. Do not silently drop a finding.
5. Follow `AGENTS.md`, especially: idempotent commands, no ad-hoc cluster edits, plans stay in `cli/plans/`, and live web verification before recommending any third-party tool or version.
6. Findings marked **Reproduced** were executed. All others come from reading the code and have not been run against a live cluster.

## Verdict

**Structural rethink needed before Phase 1** for secrets restore, project discovery, quiesce and capture, and DNS handover. Replay over Velero, the owner-tagged bundle, and two-phase cutover are sound and should stay.

## Blockers (redesign before Phase 1)

### B1. The v2 prefix lets an older LaraKube CLI delete every v2 backup (Reproduced: parsing)
- **Evidence:** `cli/app/Traits/InteractsWithBackup.php:378` parses `larakube/clusters/<id>/<stamp>/manifest.json` as stamp `clusters`, object `<id>/<stamp>/manifest.json`, complete=false. `cli/app/Commands/Backup/BackupPruneCommand.php:94-102,185` then targets `larakube/clusters/` with `s3 rm --recursive` under `--apply`.
- **Required change:** Use a sibling root that old listings never enter, such as `larakube-v2/clusters/{clusterId}/{runId}/`. Keep legacy `larakube/{stamp}/` readable. Add a test proving the legacy parser and prune cannot select a v2 object.

### B2. Commons data is restored before its databases and roles exist (Reproduced)
- **Evidence:** The plan restores Commons DBs at step E and runs `tool:init` at step F. The restore path uses `psql -d {db}` and `CREATE SCHEMA public AUTHORIZATION "{db}"` (`cli/app/Enums/DatabaseDriver.php:648,672`). Reproduced errors: `database "missing" does not exist` and `role "missing" does not exist`. `plex:export` reads only the `plex-commons` spec, so `plex-registry` is not captured.
- **Required change:** Capture a database inventory (name, owner role, extensions) and `plex-registry`. Allocate all databases and roles before restoring data. Do not assume role name equals database name for every tool; verify this per tool.

### B3. Restoring any pgvector tenant fails today (Reproduced)
- **Evidence:** The preamble drops `public` and runs `SET ROLE` before the dump's `CREATE EXTENSION IF NOT EXISTS vector` (`cli/app/Enums/DatabaseDriver.php:648`). Result: `permission denied to create extension "vector"`. The transaction rolls back and data survives, but the restore cannot succeed. ADR 0035 relies on this path for major version upgrades.
- **Required change:** Add a prerequisite fix: create captured extensions as admin before `SET ROLE`, and handle both `CREATE EXTENSION` and `COMMENT ON EXTENSION` from the dump. This fix is untested, so the plan must include a real pgvector restore test.

### B4. `secrets:export` silently loses app secrets
- **Evidence:** `cli/app/Commands/Secrets/SecretsExportCommand.php:89-104` reads only `secret/{env}/{key}`. `dotenv:push` writes `secret/{env}/{app}/{key}`, so a nested folder reads as null and is skipped while the command reports success. It also ignores any KV entry without a `value` field. Policies, auth roles, the database engine config, and static roles are not exported.
- **Required change:** Walk KV recursively, preserve the full payload, and fail on any unreadable path. Export or deterministically rebuild policies, Kubernetes auth, app grants, the database engine config, and static roles. Add a round-trip test with nested paths.

### B5. The Secret selector and ESO exclusion do not match reality
- **Evidence:** `plex-admin` (`cli/app/Commands/Plex/PlexInitCommand.php:465`) and the tool registry (`cli/app/Services/ToolRegistry.php:300`) are written without the managed-by label. `Kubectl::putSecret()` defaults to no labels and has 67 call sites. ESO templates use `creationPolicy: Merge` (`cli/resources/views/k8s/openbao/eso-sync.blade.php:42`, `eso-db-static.blade.php:37`), so "owned by an ExternalSecret" is not a reliable filter. `*-openbao-auth` Secrets contain the root token (`eso-sync.blade.php:12`, `cluster-store.blade.php:8`). ExternalSecret, SecretStore, and VaultDynamicSecret objects are never captured.
- **Required change:** Capture by namespace with an explicit denylist. Identify ESO-managed keys from the live ExternalSecret targets. Never bundle OpenBao root tokens or `larakube-backup-config`. Rebuild ESO objects during restore with the new vault's credentials. Replace the Blade-only audit with one that also covers imperative Secret creation.

### B6. Cluster-side project discovery has no labels to read
- **Evidence:** `cli/resources/views/k8s/overlays/production/namespace.blade.php` has no labels. `Labels::ENV` is used only for scoped RBAC. `Labels::PROJECT` appears on local overlays and some volumes, not production workloads.
- **Required change:** Discover from `plex-registry` tenant `namespace` values plus the `larakube-blueprint` Secret where present. Add project and env labels going forward. Report any namespace that cannot be classified instead of silently skipping it. Verify every supported framework, and confirm when the blueprint Secret is actually written (`cli/app/Traits/InteractsWithProjectConfig.php:147`).

### B7. Reusing the source `txt-owner-id` can delete live DNS
- **Evidence:** Owner IDs are `larakube-{clusterId}-{slug}` and ExternalDNS runs `--policy=sync` (`cli/app/Traits/InteractsWithClusterIdentity.php:95`, `cli/resources/views/k8s/external-dns/zone.blade.php:115-120`). On a partial jump, a destination using the source owner would treat the source's remaining records as its own orphans. Pausing source ExternalDNS also stops management of everything left behind. No native ownership-transfer procedure was found upstream.
- **Required change:** Always give the destination its own owner ID. Transfer only the moved hosts' records and TXT ownership through provider APIs, after verifying the exact TXT format for the pinned ExternalDNS version. Pause the source only for `--all`. Define behavior per provider (Cloudflare, Route53, Google).

### B8. Quiesce removes the pods capture depends on
- **Evidence:** The plan scales workloads to 0, then capture runs `kubectl exec deploy/...` (`cli/app/Commands/Backup/BackupRunCommand.php:131`). The existing migration keeps `web` up for this reason (`cli/app/Commands/Cloud/CloudMigrateCommand.php:416-421`). The scheduler is a CronJob (`cli/resources/views/k8s/scheduler/cronjob.blade.php:2`) and is not suspended by `QuiescesAppDeployments`.
- **Required change:** Define quiesce per workload type: block traffic at ingress, stop workers, suspend CronJobs, and capture through a live reader or a subPath-aware helper pod. Include non-Laravel frameworks, which have no `artisan down`. Verify HPA behavior.

### B9. The nightly CronJob cannot produce the proposed bundle
- **Evidence:** `cli/resources/views/k8s/backup/cronjob.blade.php:27-39` grants pods, exec, and deployments only. It has no Secret access, cannot mount `plex-admin` across namespaces, and has neither the PHP CLI nor the OpenBao token. Its namespace list is frozen at schedule time (lines 9, 40-55). This breaks Decision 1 for nightly backups.
- **Required change:** Design a dedicated collector with explicit, minimal RBAC, and handle projects added after scheduling. If that is rejected, state clearly that only operator-run bundles are portable and show that in `backup:status`.

### B10. Cluster identity already exists
- **Evidence:** `cli/app/Traits/InteractsWithClusterIdentity.php:38-84` stores `cluster-id` in ConfigMap `larakube-cluster`, and DNS owner IDs depend on it. The plan marks this trait as new and proposes `larakube-cluster-identity`.
- **Required change:** Reuse the existing trait and ConfigMap. Never restore a source identity onto a destination.

## High-risk gaps (amend before coding)

- **H1. Flag collisions.** `backup:restore --bucket` already means the off-site bucket (`cli/app/Commands/Backup/BackupRestoreCommand.php:39`); use a distinct name such as `--object-bucket`. `--tool` is a slug across the LaraKube CLI and hosts use `--domain`, so use `--tool=<slug> --domain=<host>`. `tool:init --host` does not exist.
- **H2. Replay inputs are incomplete.** Install options exclude credentials and custom env (`cli/app/Traits/DeploysClusterTool.php:372-395`), and ConfigMaps are not captured. ExternalDNS throws `MissingFlagException` when its token is missing. Define a per-tool replay matrix, including dependency order and `sso:wire`, `mail:wire`, `secrets:wire`, `tool:alias`, `tool:env:set`, `tool:resources`, `tool:proxy`, and `tool:pause`. The storage for alias, resources, and proxy **was not traced** and must be checked.
- **H3. "An omission can't lose data" is false for volumes.** Discovery covers Deployments only (`InteractsWithBackup.php:133-148`), so Supabase's StatefulSet and pgsodium key are missed (ADR 0032). Check bundled `--no-plex` databases too. Inventory every PVC and require each to be captured or explicitly excluded with a reason.
- **H4. Automated volume restore is unsafe with `subPath`.** `resolveVolumeClaim()` drops it (`InteractsWithBackup.php:479-519`), and Stalwart, Matrix, oCIS, and NetBird templates use it. Fix this before automating fresh-target restores.
- **H5. Paused tools get restarted.** `currentReplicas()` forces at least 1 (`BackupRestoreCommand.php:517-524`), violating ADR 0030. Preserve paused state through restore and rollback.
- **H6. Merge collisions.** Tenants share Commons S3 and Meilisearch keys, and Redis indexes are per-cluster (`cli/app/Commands/Plex/PlexJoinCommand.php:262-355`). A merge must re-allocate these and rewrite tenant config. It must never restore `plex-admin`, overwrite the destination registry, or reuse a source Redis index.
- **H7. Garage credentials.** A pre-restored `plex-admin` blocks saving the newly created Garage key (`PlexInitCommand.php:427-437`). Define credential restore per storage driver for SeaweedFS, MinIO, and Garage.
- **H8. S3 fidelity.** Copying through local files loses stored `Content-Type`, `Cache-Control`, and user metadata, because the AWS CLI guesses types from extensions on upload. Capture a metadata sidecar, or verify `rclone -M` against all three engines first. Define handling for empty buckets, ACLs, and CORS. Keep the SeaweedFS tar until a logical restore drill passes.
- **H9. Disk exhaustion.** The CronJob `emptyDir` has no size limit, and plaintext and ciphertext coexist during encryption (`cronjob.blade.php:86-88,196-201`). Stream encryption, preflight free space, and review the 600-second timeouts for large buckets.
- **H10. Concurrency.** A nightly run during quiesce can publish a partial run with a valid manifest (`cronjob.blade.php:145-167,252-254`). Add a cluster-wide lease, make default restore pick only complete `scope=cluster` runs, and pin a jump's run against pruning.
- **H11. Encryption and manifest exposure.** `openssl enc` CBC is unauthenticated and a SHA-256 in a writable manifest does not stop tampering. Add an HMAC using a passphrase-derived key, verified before decryption, plus an `encryption` version field. Move hosts, owners, context, and external topology into an encrypted index. Remove the passphrase from argv (`BackupRunCommand.php:165`, `BackupRestoreCommand.php:232`).
- **H12. Rollback and re-run safety.** Record original replicas once, so a re-run cannot overwrite them with 0. Refuse `--rollback` after cutover unless the operator accepts losing destination writes. Specify recovery for each step of `cloud:migrate` and `backup:restore`.
- **H13. Lock hard-to-change decisions before Phase 1:** bucket root, run ID format, manifest and encryption versioning, the single cluster identity, restore marker key, and flag names.

## Medium / low observations

- **M1.** Staging on HTTP-01 fails while DNS points at the source and can burn Let's Encrypt validation attempts. Delay issuance or use the existing DNS-01 path (`cli/resources/views/k8s/traefik-managed.blade.php:162-172`).
- **M2.** Key restore markers by cluster ID and run ID. Stamps are second-granular, so scoped runs need a unique run ID.
- **M3.** Legacy runs have no cluster owner. Define how `--cluster` pruning treats them.
- **M4.** Commons Meilisearch data is not captured. Report that a reindex is required after restore.
- **M5.** MySQL and MariaDB restores do not clear tables absent from the dump.
- **M6.** The `--endpoint` restore path hardcodes region `auto` (`BackupRestoreCommand.php:566`), which conflicts with the planned AWS default.
- **M7.** Keep `id`, `taken`, `bytes`, and `items` stable in `--json` output so the current Desktop app keeps working. Desktop does not call `cloud:migrate` yet.
- **M8.** Removing `cloud:migrate --quiesce` is a breaking flag change. Keep it as an accepted no-op.
- **M9.** This prompt's `resources/views/k8s/dns/*` path does not exist. The real template is `cli/resources/views/k8s/external-dns/zone.blade.php`.

## External references checked

- [ExternalDNS owner ID guidance](https://github.com/kubernetes-sigs/external-dns/issues/1441)
- [ExternalDNS TXT registry](https://kubernetes-sigs.github.io/external-dns/v0.14.1/registry/registry/)
- [AWS CLI `s3 sync`](https://docs.aws.amazon.com/cli/latest/reference/s3/sync.html)
- [OpenSSL `enc`](https://docs.openssl.org/3.5/man1/openssl-enc/)
- [rclone metadata discussion](https://forum.rclone.org/t/metadata-update-local-s3/32277.md) (forum source; verify against official rclone docs before adopting)


---

# Final Sign-Off Instructions for Claude

Antigravity has amended `cli/plans/active/portable-backups-and-cloud-jump.md` to resolve **B1–B10, H1–H13, and M1–M9**, and the user confirmed **Decision #15** (two-tier CronJob model: `data` default + opt-in `--portable`).

Please do a **final sign-off verification** (do NOT edit code or ADRs; append your verdict to this file under `# Final Sign-Off Verdict`):

1. Read the amended `cli/plans/active/portable-backups-and-cloud-jump.md` in full and check every row of its **Review resolution table** against B1–B10, H1–H13, and M1–M9 above.
2. Verify any new design choices introduced by the amendments against the codebase (especially:
   - `larakube-v2/clusters/{clusterId}/{runId}/` sibling root & `BackupCrypto` Encrypt-then-MAC + encrypted `index.json.enc`,
   - Phase 0 prerequisites: pgvector `CREATE EXTENSION` / `COMMENT ON EXTENSION`, MySQL clean restore, recursive `secrets:export`, `subPath` helper mount, paused-replica preservation,
   - `bucket-{id}.metadata.json` sidecar via `aws s3api` instead of `rclone -M`,
   - Per-workload quiesce with Traefik maintenance Middleware + CronJob suspend + HPA pause,
   - Per-host TXT owner rewrite before target flip under `external-dns:v0.24.0`,
   - Garage vs SeaweedFS/MinIO `plex-admin` S3 key handling,
   - Two-tier CronJob model (`data` vs `--portable` with scoped read-only OpenBao export token)).
3. Report:
   - Any remaining **Blockers** or **unaddressed gaps** (if any), with exact evidence and fixes.
   - **Final Verdict:** `APPROVED FOR EXECUTION (Phase 0+)` or `CHANGES REQUIRED`.

---

# Final Sign-Off Verdict

## **CHANGES REQUIRED**

The amendments fixed most of the original review, and the overall architecture is now sound. Do not start Phase 0 yet: several amendments conflict with the code, and Locked Contracts #2, #4, and #6 would freeze incorrect decisions.

**Verification level.** Items marked **Reproduced** were run on disposable local containers. Everything else comes from reading the code. Nothing was run against a live cluster.

## Instructions for Antigravity

1. Amend `cli/plans/active/portable-backups-and-cloud-jump.md` to resolve **R1-R19** below, and add them to its Review resolution table.
2. **Change the plan only.** Do not implement code, change ADRs, or touch a cluster. Stop afterward for sign-off.
3. Re-check each cited line first. If a proposed fix is wrong, keep the requirement, record the alternative and its evidence, and flag it for the user.
4. Follow `AGENTS.md`, including live verification before pinning any third-party image or version.
5. Apply the user decision below (**R0**) across the whole plan.

## R0. User decision: this is the first backup format

For LaraKube CLI users, this is the first backup format. **Remove all "v2", "legacy", and "old format" language and behavior from the plan, including names, paths, flags, classes, tests, and docs.** Where this review says "v2", read it as "the backup format". This supersedes every plan item that preserves existing `larakube/{stamp}/` backups.

- **Bucket root:** `larakube-backups/clusters/{clusterId}/{runId}/`. It replaces `larakube-v2/` and still sits outside the `larakube/` prefix, so a pre-release binary's `backup:prune --apply` cannot reach it (B1). Keep that test, without describing a supported older format.
- **Versioning:** `manifest.json` uses `"version": 1`. The encryption field is `"encryption": "aes256cbc-hmacsha256-1"`.
- **Remove:** the old manifest reader and normalisation, the `v1-aes256cbc` decrypt path, `layout`, listing of the `larakube/` root, `--cluster=legacy`, `--include-legacy`, support for old runs in restore and `--deep`, backward-compatibility tests, and manual verification step 5. M3 no longer applies.
- **Simplify:** `BackupManifest` handles this one format. Do not add migration or cleanup code for earlier backups.
- **Keep the Phase 0 restore fixes:** the new format uses the same restore paths.
- **Keep Desktop compatibility:** the digits-only run ID (R3) and stable `--json` keys are still needed.
- **Existing CronJobs:** treat re-running `backup:schedule` as a rollout step for the user's own clusters, not a documented upgrade path.
- **Existing backups are disposable.** The user has explicitly said they can be deleted. Do not plan around them, preserve them, or gate any phase on them.

## Status of the original findings

| Status | IDs |
|---|---|
| Resolved | B1, B6, B10, H1, H3, H5, H6, M3, M4, M6, M8, M9 |
| Partly resolved | B2 (R4, R16), B3 (R4), B4 (R6), B5 (R1, R11), B7 (R14), B8 (R8, R13), B9 (R9), H2 (R6, R15), H4 (R7), H7 (R12), H8 (R17), H9 (R2, R9), H10 (R3, R19), H11 (R2), H12 (R14), H13 (R2, R3, R10), M1 (R18), M2 (R3), M5 (R5), M7 (R3) |

## Remaining blockers

### R1. The denylist misses the real OpenBao root token and unseal key
- **Evidence:** No code uses the name `openbao-bootstrap`. The real Secret is instance-named through `secretsNames()->secret()` (`cli/app/Traits/InteractsWithSecrets.php:212-216,276`). The root token is also copied to `eso-openbao-token` (`cli/resources/views/k8s/openbao/cluster-store.blade.php:4-8`), which `*-openbao-auth` does not match.
- **Required change:** Resolve the bootstrap Secret name through `ToolInstance`, and deny `eso-openbao-token`, `*-openbao-auth`, and any collector token Secret. Add a fail-closed capture guard that aborts if any captured value equals the live root token or unseal key.

### R2. The encryption envelope cannot be produced by the CronJob
- **Evidence (Reproduced):** The pinned `alpine/openssl:3.5.4` has no `-hmac-env`, so HMAC keys would have to go on argv. It also has no PHP, Python, Perl, jq, or curl, so `BackupCrypto` cannot run there. OpenSSL 4.1.0 on this machine does have `-hmac-env`.
- **Required change:** Specify one byte-exact envelope that both PHP and the CronJob can produce, with cross-implementation test vectors. Verify and pin an image that supports key input outside argv.
- **Also lock in Contract #4:** KDF iterations and labels; byte layout; and an HMAC that binds `clusterId`, `runId`, object name, and format version, so valid objects cannot be swapped between positions or runs.
- **Fix contradictions:** `bucket-{id}.metadata.json` must be encrypted. `secrets-k8s-{ns}.yaml.enc` leaks project namespaces, so use opaque IDs. Keep pin state only in `pinned.json`, because `manifest.json` is a write-once commit marker.

### R3. The `runId` format breaks Desktop and retention
- **Evidence:** Desktop accepts only `^[0-9-]+$` (`desktop/app/Http/Controllers/BackupController.php:256,276`), so hex suffixes are rejected. `stampTime()` accepts only `Y-m-d-His` and treats anything else as keep-forever (`cli/app/Commands/Backup/BackupPruneCommand.php:231-236,257-264`), so new runs would never be pruned. Retention per `(clusterId, scope_kind)` also lets newer nightly `data` runs displace the only `portable` run.
- **Required change:** Use a digits-only suffix such as `2026-11-23-183012-482901`, update `stampTime()` and CronJob pruning, and apply retention per `(clusterId, scope_kind, tier)`. Add tests for all three.

### R4. The Postgres restore fix is incomplete
- **Evidence (Reproduced):** Creating `vector` in `public` as admin before `SET ROLE` works. Two cases still fail: a multi-schema tenant restored over itself (`schema "eventstore" already exists`), and an extension in a custom schema on a fresh database (`schema "extensions" does not exist`).
- **Required change:** Restore into a fresh shadow database, verify it, then swap by rename so a failure leaves the original intact. Pre-create extension-hosting schemas as admin, filter only the matching dump statements, and verify table ownership afterward. Test multi-schema, custom-schema, and pgvector tenants on a real Postgres.

### R5. The MySQL/MariaDB clean restore can destroy data on failure
- **Evidence:** The plan proposes `DROP DATABASE` + `CREATE DATABASE`. MySQL DDL is not transactional, so a failed import leaves an empty database. The client also needs `USE` after the drop, and charset and collation must be preserved.
- **Required change:** Use a shadow database with a verified table swap, or take and verify a safety dump first. Never drop the only copy before the replacement is proven.

### R6. Static-role and vault restore order violates the rotation rule
- **Evidence:** Step 5 registers static roles and ExternalSecrets before tool replay. Project rules say only `secrets:wire` starts rotation (with SSO and Mail as the exceptions), and a migration recipe must end with `secrets:wire` + `secrets:prune`. Registering a role rotates its password immediately (`cli/app/Traits/SyncsClusterSecrets.php:351-360`). OpenBao's connection config must also be rebuilt from the destination's admin password, and Kubernetes auth must use the destination CA (`SyncsClusterSecrets.php:231-271,600-612`).
- **Required change:** Run rotation wiring after each tool is restored and healthy, through `secrets:wire` or the existing SSO, Mail, and tenant paths, then run `secrets:prune`. Treat database and Kubernetes auth config as rebuilt state and never restore source connection credentials or CA.

### R7. Single-`subPath` capture still loses Stalwart state
- **Evidence:** One Stalwart claim mounts `data` and `etc` (`cli/resources/views/k8s/stalwart/stalwart.blade.php:219-224`), but its backup path is only `/var/lib/stalwart` (`cli/app/Vendors/MailTool.php:78`). The template notes that `config.json` names the database, so the whole claim must be copied.
- **Required change:** Archive and restore each PVC from its root through a helper pod.

### R8. The Traefik maintenance Middleware is not available on every install
- **Evidence:** `--providers.kubernetescrd` is enabled in `traefik-cloud.blade.php:143`, but not in `traefik-managed.blade.php:159-160` (used by DOKS, EKS, and GKE) or `traefik-install.blade.php:132-134`. Traefik also has no built-in "return 503" middleware, and many tools already use `router.middlewares` for VPN or SSO.
- **Required change:** Prefer patching moved Ingress backends to a small maintenance Service, recording the original spec once, so VPN and SSO annotations are preserved. If Middleware stays, enable the provider everywhere and append to existing annotations without replacing them.

### R9. The default `data` tier and `--portable` collector are underspecified
- **Evidence:** The default CronJob cannot read `plex-admin`, so it cannot do logical bucket capture. Retiring the SeaweedFS tar would therefore remove object data from nightly backups. It has no Lease or PVC RBAC (`cli/resources/views/k8s/backup/cronjob.blade.php:27-39`), and no existing image can run the proposed collector.
- **Required change:** Keep a raw archive of the active S3 engine in the `data` tier permanently, for all three drivers. Add only the Lease permission it needs. Move `backup:schedule --portable` to a later phase until its image, RBAC, token policy, and renewal are designed. Decision #15's default stays intact.

### R10. `--cutover` and `--rollback` break the no-hidden-flag rule
- **Evidence:** Project rules require distinct operations, especially inverse or destructive ones, to be standalone commands rather than flags.
- **Required change:** Use standalone commands such as `cloud:migrate:cutover` and `cloud:migrate:rollback`, sharing logic through a trait. Do not implement them as wrappers around a hidden flag. Update Decisions #12-13, Contract #6, and the `--json` contract.

## Required high-risk fixes

- **R11. ConfigMap denylist.** `larakube-cluster` is a ConfigMap but appears only in the Secret denylist. Deny `larakube-cluster`, `kube-root-ca.crt`, restore and migration markers, and on merges `plex-registry` and `plex-commons`.
- **R12. Garage credentials.** Choose one approach. `plex:init` creates `commons-admin`, then refuses to save it if `plex-admin` already has keys (`cli/app/Commands/Plex/PlexInitCommand.php:425-437`). Prefer importing the original key as `commons-admin` through Garage's Admin API after verifying this on the pinned v2.1.0. Otherwise re-mint and rewrite every consumer.
- **R13. Quiesce must complete before capture.** Wait for scaled pods to terminate. Check the official Kubernetes docs for CronJob `suspend`, then wait for or stop active Jobs. Do not set `minReplicas: 0`: rendered HPAs are CPU-based, and Kubernetes rejects zero for CPU-only HPAs.
- **R14. DNS handover and rollback.** Rewrite every registry variant, including classic and `a-`/`cname-` TXT records, and include aliases. Journal each host's original records before changing them. Define rollback for `cutover-in-progress`, including a partial flip.
- **R15. SSO replay.** Do not blindly replay `sso:wire`. On `--all`, reuse the restored Zitadel project IDs. On a merge into another Zitadel, report that grants must be recreated, and compare grant counts before and after.
- **R16. Role credential source.** Define where each role's password comes from at step 4: static role, captured Secret, or generate-and-rewrite. Capture non-owner grants, or use a tested `--no-privileges` policy.
- **R17. S3 completeness.** Local files cannot represent both `a` and `a/b`, and directory-marker objects can be lost. Compare object counts and keys after capture and fail loudly on a mismatch. Tolerate `NotImplemented` for CORS and policy per driver.
- **R18. Certificate deferral has no implementation.** 46 templates reference `certresolver`. Name the single mechanism that defers issuance during staging and the phase that delivers it.
- **R19. Lease semantics.** Define holder identity, TTL, renewal, stale-lock recovery, and whether the lease spans only capture or the whole staged period. A staged jump should rely on its pin and migration marker, not a lease held for days.

## Separate issue to check

`--vpn-only` and ForwardAuth SSO rely on Traefik Middleware, but the managed-cluster Traefik template does not enable the CRD provider, and `traefikMiddlewareCrdExists()` checks only that the CRD exists. Check this on a managed cluster independently of this plan, because it may affect access controls.

## External references checked

- [ExternalDNS TXT registry](https://kubernetes-sigs.github.io/external-dns/latest/docs/registry/txt)
- [Kubernetes v1.37 HPA scale-to-zero](https://kubernetes.io/blog/2026/09/02/kubernetes-v1-37-hpa-scale-to-zero-beta/)
- [Garage Admin API key import (SDK docs)](https://git.deuxfleurs.fr/garage-sdk/garage-admin-sdk-python/src/commit/0e76d0eb8c43fc958bac9d7159321d99368bda37/docs/KeyApi.md) (verify against the pinned server version)


---

# Final Sign-Off Pass 2 Instructions for Claude

Antigravity has amended `cli/plans/active/portable-backups-and-cloud-jump.md` to apply **R0** (first production backup format; all v2/legacy language and paths removed) and resolve **R1–R19** in full, updating the **Review resolution table**.

Please do a quick **Pass 2 sign-off check** (do NOT edit code or ADRs; append your verdict under `# Final Sign-Off Verdict (Pass 2)`):
1. Verify **R0** is applied cleanly throughout `cli/plans/active/portable-backups-and-cloud-jump.md` (zero leftover v2/legacy paths, flags, or tests).
2. Verify **R1–R19** are resolved cleanly with no remaining code conflicts or contract contradictions (especially Locked Contracts #1–#6: `larakube-backups/`, digits-only `runId`, byte-exact `aes256cbc-hmacsha256-1` via `php:8.4-cli-alpine`, opaque IDs, pin-only `pinned.json`, standalone `cloud:migrate:cutover` / `cloud:migrate:rollback`, shadow-DB restores, post-restore `secrets:wire` + `secrets:prune`, PVC-root helper, Ingress maintenance Service, HPA snapshot/delete, content-addressed S3 tar, and `DefersStagingTls`).
3. Report **Final Verdict:** `APPROVED FOR EXECUTION (Phase 0+)` or any remaining blocker.

---

# Final Sign-Off Verdict (Pass 2)

## **CHANGES REQUIRED**

R0 is applied cleanly. The only remaining "v2" references are OpenBao's KV v2 engine, and the "legacy" references are review history. Most of R1-R19 are resolved. Six blockers remain, mainly where the operator-run design was applied to the nightly job or to deploy paths it does not control.

**Verification level.** Run on disposable containers: the PHP envelope, `garage key import`, and the contents of the pinned Garage and SeaweedFS images. Everything else comes from reading the code. Shadow-database restores have not been run, and nothing was run against a live cluster. The pinned MinIO image could not be pulled from this machine (`pull access denied`), so its contents are unverified.

## Instructions for Antigravity

1. Amend `cli/plans/active/portable-backups-and-cloud-jump.md` to resolve **S1-S12**, and add them to its Review resolution table.
2. **Change the plan only.** Do not implement code, change ADRs, or touch a cluster. Stop afterward for sign-off.
3. Re-check each cited line first. If a proposed fix is wrong, keep the requirement, record the alternative and its evidence, and flag it for the user.

## Status of R0-R19

| Status | IDs |
|---|---|
| Resolved | R0, R1, R3, R4, R5, R10, R13, R14, R15, R16, R17, R19 |
| Resolved after a small correction | R2 (S8, S9, S11) |
| Partly resolved | R6 (S6), R7 (S1), R8 (S5, S7), R9 (S2), R11 (S3), R12 (S2, S12), R18 (S4) |

## Remaining blockers

### S1. The nightly job cannot produce PVC-root archives
- **Evidence:** Decision #7 and Phase 2 archive every PVC from its root through a helper pod. The default CronJob can only get and list pods and exec into them (`cli/resources/views/k8s/backup/cronjob.blade.php:31-39`), and the plan adds only Lease RBAC. A helper pod would also require stopping each RWO workload every night. The nightly job would therefore keep producing mount-relative archives that the PVC-root restore cannot read, and Stalwart's `etc` subPath would still be missing.
- **Required change:** Define a second capture mode for the `data` tier. Archive every PVC-backed mount of a live workload, and record `{pvc, subPath, mountPath}` per archive in `index.json.enc` so restore writes each one to the right place under the PVC root. Skip Secret and ConfigMap mounts nested inside those paths, such as Stalwart's `config.json`. Test restoring a nightly Stalwart archive.

### S2. The raw S3 archive cannot work on Garage
- **Evidence (Reproduced):** The pinned `dxflrs/garage:v2.1.0` image contains only the `garage` binary, with no `sh` or `tar`. The `data` tier's `kubectl exec ... tar` therefore cannot run. `PlexService::createBucket()` also runs `sh -c` in that container (`cli/app/Services/PlexService.php:620-625`), so Garage bucket allocation appears broken today. SeaweedFS has both tools. MinIO is unverified.
- **Required change:** Replace "raw archive for all three engines" with a per-driver strategy. Keep the tar for SeaweedFS. For Garage, use logical capture with a dedicated read-only key available to the job, including how new buckets get read access. If a driver cannot be captured, `backup:status` and the Job must report object storage as not covered. Add a Phase 0 fix for Garage bucket creation without a shell. Verify that the pinned MinIO image is still pullable and what it contains.

### S3. The merge denylist names the wrong registry object
- **Evidence:** The plan denies a ConfigMap named `larakube-tool-registry` (plan lines 144, 241). The real object is the **Secret** `larakube-tools-registry` (`cli/app/Services/ToolRegistry.php:24`). A merge would capture and apply the source registry over the destination's, replacing its tool list.
- **Required change:** Correct the name and kind, deny it in the Secret list on merges, and merge only the moved tool's row through `ToolRegistry`. Reference the class constants rather than string literals. Add a test proving the destination's existing rows survive.

### S4. `DefersStagingTls` does not cover the real apply paths
- **Evidence:** The plan hooks only `DeploysClusterTool` and `CloudDeployCommand`. `apply -f` appears 93 times across commands and traits, and project deploys pipe `kustomize build` straight into `kubectl apply` (`cli/app/Traits/InteractsWithRemoteDeploy.php:205-228`). Any missed path can trigger HTTP-01 during staging.
- **Required change:** Control the annotation at render time. Route all 46 `router.tls.certresolver` template sites through one shared helper that reads the staging state, and add a test that fails if a template hardcodes the annotation. Define how the state reaches kustomize-rendered project manifests.

### S5. A deploy during quiesce silently restarts writes
- **Evidence:** Quiesce stores state in Ingress backends, replica counts, CronJobs, and HPAs. `cloud:deploy`, `tool:update`, or a CI deploy re-applies those manifests, restoring traffic and workers while the jump believes the source is frozen. Writes after the snapshot would be lost at cutover.
- **Required change:** Make `cloud:deploy`, tool update and init paths refuse to run against a scope with an active migration marker. Define the CI behavior. Immediately before the final capture and again before cutover, re-verify the quiesced state and abort on drift.

### S6. Post-restore rotation could enroll tools that were never wired
- **Evidence:** The bundle deliberately excludes static roles, and the plan says to "run `secrets:wire`". `secrets:wire --all` wires every installed DB-rotatable tool (`cli/app/Commands/Secrets/SecretsWireCommand.php:138-152`), which would start rotation on tools that never had it.
- **Required change:** Capture static role names, rotation periods, and their tool or tenant mapping as non-secret metadata. Replay with `secrets:wire --tool= --domain=` only for those tools, plus the existing SSO, Mail, and tenant paths. Never use `--all` here. End with `secrets:prune`.

## Required corrections

- **S7. Maintenance Service.** Remove the "ExternalName/cross-namespace" option. Ingress backends are namespace-local, and LaraKube does not enable Traefik's `allowExternalNameServices`. Use one small per-namespace Service and workload, and define its image and cleanup.
- **S8. Passphrase contradiction.** Contract #4 forbids argv, but Contract #6 keeps `--passphrase=`. Replace it with an environment variable or passphrase file for DR restores. Apply the same treatment to `--secret-key=`.
- **S9. Exact image pin.** `php:8.4-cli-alpine` is a floating tag, and the project standard is PHP 8.5. `php:8.5-cli-alpine` currently resolves to 8.5.13. Verify the latest stable release and pin an exact patch tag.
- **S10. Multi-node project storage.** Multi-node deployments replace the storage PVC with a per-pod `emptyDir` (`cli/resources/views/k8s/overlays/production/storage-emptydir.blade.php`), so PVC-root capture finds nothing. Detect this and report it as non-durable storage instead of implying it was backed up.
- **S11. Streaming rule.** Add chunking to Contract #4: encrypt 16-byte-aligned chunks with raw CBC chaining, and apply PKCS#7 only to the final chunk. A prototype on `php:8.4-cli-alpine` round-tripped 128MB with a 10MB peak and verified the HMAC first. Also require restore to treat `index.json.enc` as authoritative over the unauthenticated `manifest.json` fields.
- **S12. Garage import.** `garage key import` exists on v2.1.0. Specify `-n commons-admin`, because bucket grants reference that key name.


---

# Final Sign-Off Pass 3 Instructions for Claude

Antigravity has amended `cli/plans/active/portable-backups-and-cloud-jump.md` to resolve **S1–S12** in full and updated the **Review resolution table**.

Please do a quick **Pass 3 sign-off check** (do NOT edit code or ADRs; append your verdict under `# Final Sign-Off Verdict (Pass 3)`):
1. Verify **S1–S12** are cleanly resolved with no remaining contradictions or codebase conflicts (`live-mounts` + `pvc-root`, Garage direct-argv fix + read-only `backup-reader` key + MinIO dead-Docker-Hub handling, `ToolRegistry::SECRET` merge denylist, `IngressTls` render-time helper + kustomize filter + static test, `ChecksMigrationLock` + pre-capture/pre-cutover drift checks, selective `secrets:wire --tool= --domain=`, per-namespace `larakube-maintenance` on `php:8.5.13-cli-alpine`, no `--passphrase=`/`--secret-key=` argv flags, `emptyDir` detection, 64 KiB raw-CBC chunking + authoritative `index.json.enc`, and `garage key import ... -n commons-admin`).
2. Report **Final Verdict:** `APPROVED FOR EXECUTION (Phase 0+)` or any remaining blocker.

---

# Final Sign-Off Verdict (Pass 3)

## **CHANGES REQUIRED**

The plan is close. Four narrow blockers remain, and three are concentrated in Garage and migration-lock mechanics. **Phase 0 items 2 (shadow-database restores) and 3 (recursive OpenBao export/import) do not depend on any finding below.**

**Verification level.** Run on disposable containers: `php:8.5.13-cli-alpine`, `amazon/aws-cli:2.27.22`, and a real single-node `dxflrs/garage:v2.1.0`. Everything else comes from reading the code. Nothing was run against a live cluster.

## Instructions for Antigravity

1. Amend `cli/plans/active/portable-backups-and-cloud-jump.md` to resolve **T0-T8**, and add them to its Review resolution table.
2. **Change the plan only.** Do not implement code, change ADRs, or touch a cluster. Another agent is removing MinIO from the LaraKube CLI and LaraKube Desktop, so do not edit those files or duplicate that work.
3. Re-check each cited line first, because the MinIO removal may move code. If a proposed fix is wrong, keep the requirement, record the alternative and its evidence, and flag it for the user.

## T0. User decision: MinIO is dead

Remove MinIO from the entire plan: decisions, driver strategies, replay tiers, tests, the separate-issue note, and any "off MinIO" migration path. The supported Commons S3 drivers are **SeaweedFS and Garage**. Do not add MinIO compatibility, detection, or a `not_covered` branch for it. Phase 0 must build on the MinIO removal once that work lands.

## Status of S1-S12

| Status | IDs |
|---|---|
| Resolved | S3, S7, S9, S10 |
| Resolved after a small correction | S1 (T7), S6 (T6), S8 (T8), S11 (T2) |
| Partly resolved | S2 (T0, T1, T4), S4 (T3), S5 (T3), S12 (T1) |

## Remaining blockers

### T1. The Garage bootstrap and credential flow still cannot work
- **Evidence:**
  - `ensureGarageBootstrap()` also runs through `sh -c` (`cli/app/Commands/Plex/PlexInitCommand.php:351-355`), but the pinned image has no shell. Phase 0 fixes only the bucket functions.
  - `ensureCommonsSecret()` runs first (line 109) and fills missing S3 keys with `larakube` plus a random secret (lines 452-458). The bootstrap then refuses to save the real Garage key because `plex-admin` is already populated (lines 425-437). The plan's "strip S3 keys" step is undone by this.
  - **Reproduced:** if bootstrap creates `commons-admin` and the original key is then imported with the same name, `bucket allow --key commons-admin` fails with `2 matching keys`.
  - **Reproduced:** `garage bucket create` exits 1 when the bucket already exists.
- **Required change:** In Phase 0, run every Garage command without a shell, including `StorageDriver.php:473,487,497,508` and the bootstrap. Do not generate placeholder S3 keys for Garage. On restore, import the original key **before** the bootstrap's name check, so only one `commons-admin` exists. Make bucket creation idempotent by checking for the bucket first, while still applying `allow`. Add a real-Garage test for fresh init, restore, and re-run.

### T2. Locked Contract #4 contains code that throws
- **Evidence (Reproduced):** `hash_init('hmac', HASH_HMAC, $macKey)` throws `ValueError` on PHP 8.5.13.
- **Required change:** Use `hash_init('sha256', HASH_HMAC, $macKey)`. State that the encryptor must look ahead so the last chunk is the one padded. With that rule, chunked output matched standard AES-256-CBC at sizes 0, 1, 15, 16, 65535, 65536, 131072, and 131073. An exact-multiple input must end with one 16-byte padding block.

### T3. The migration lock blocks its own orchestration
- **Evidence:** `ChecksMigrationLock` makes `cloud:deploy` and `tool:init` refuse while a marker is `quiesced` or `staged`. `cloud:migrate` must call those same commands on the destination during staging, and each project finalize runs `cloud:deploy` after `--all` has staged. The plan also never says which cluster stores `larakube-migration-*`, and a dead source cannot hold it. `StagingTls::isDeferred()` has no defined state source.
- **Required change:** Write a marker on each live cluster with `role: source|destination`. The source role blocks deploy and tool mutations. The destination role allows them only from the migration flow through an in-process context, not a public flag, and blocks unrelated operator changes to the moved scope. `StagingTls::isDeferred()` reads the destination marker, so later commands in another process get the same answer. For DR, the destination marker alone is sufficient. Keep any operator override as a clearly destructive, documented escape hatch.

### T4. Nightly Garage capture has no runnable stage
- **Evidence (Reproduced):** `amazon/aws-cli:2.27.22` has `sha256sum` but no `tar` or `gzip`. The PHP encrypt image cannot talk to S3 without a client. The plan does not define where the Garage bucket archive is built. "Auto-granted on bucket creation" also misses buckets that already exist when `backup:schedule` runs.
- **Required change:** Define the CronJob stages explicitly: download objects and metadata in the AWS image to the shared `emptyDir`, build the archive in an image verified to contain `tar`, then encrypt. On `backup:schedule`, create or reuse `backup-reader` idempotently and grant it read access to **all existing** buckets. Bucket creation keeps new buckets covered.

## Required corrections

- **T5. Bucket count guard.** After the Garage download, compare keys against the live listing before encrypting, and report the run as incomplete on any mismatch.
- **T6. Tenant rotation path.** `wireTenantDbSecret()` is a protected method on `PlexJoinCommand` (`PlexJoinCommand.php:585`). Extract it to a trait so migration can call it without a project `cwd`. Because the ExternalSecret uses `creationPolicy: Merge`, make sure its target Secret exists first.
- **T7. Images without `tar`.** `live-mounts` depends on `tar` in every workload image, and Garage proves that isn't universal. Preflight each container, exclude Commons S3 and logically dumped database PVCs, and record anything else as `not_covered` in `index.json.enc` and `backup:status`.
- **T8. `backup:init --secret-key`.** Apply the no-argv rule here too. Desktop already passes `LARAKUBE_BACKUP_SECRET_KEY` through the environment.


---

# Final Sign-Off Pass 4 Instructions for Claude

Antigravity has amended `cli/plans/active/portable-backups-and-cloud-jump.md` to apply **T0** (MinIO removed completely; SeaweedFS & Garage only; Phase 0 builds on the MinIO removal) and resolve **T1–T8** in full, updating the **Review resolution table**.

Please do a quick **Pass 4 final sign-off check** (do NOT edit code or ADRs; append your verdict under `# Final Sign-Off Verdict (Pass 4)`):
1. Verify **T0** is applied cleanly (zero MinIO strategies, tests, branches, or notes left).
2. Verify **T1–T8** are cleanly resolved with no remaining blockers (`ensureGarageBootstrap` + all Garage ops shell-less, no placeholder S3 keys for Garage, import `-n commons-admin` before name check, idempotent bucket create + real-Garage tests; `hash_init('sha256', HASH_HMAC, $macKey)` + lookahead chunking; dual `role: source|destination` migration markers + in-process `MigrationRuntimeContext` + destination-backed `StagingTls::isDeferred()`; 5-stage Garage CronJob pipeline + `backup-reader` backfill + T5 count/key guard; `WiresTenantDbSecrets` trait + target Secret check; `live-mounts` `tar` preflight + `not_covered`; `backup:init` secret-key off argv).
3. Report **Final Verdict:** `APPROVED FOR EXECUTION (Phase 0+)` or any remaining blocker.

---

# Final Sign-Off Verdict (Pass 4)

## **APPROVED FOR PHASE 0 ONLY. PHASE 1 BLOCKED UNTIL U1-U5 ARE IN THE PLAN.**

T0 is applied cleanly: the only MinIO references left state that it is being removed and that Phase 0 builds on that work. T2, T3, T5, T6, T7, and T8 are resolved. T1 and T4 need the additions below.

**Phase 0 execution order.** Items 2, 3, and 4 can start now. Item 1 (Garage) must wait for the MinIO removal to land and must include U3 and U4.

**Verification level.** Run on disposable containers: `alpine/k8s:1.34.1`, `amazon/aws-cli:2.27.22`, `php:8.5.13-cli-alpine`, and a real single-node `dxflrs/garage:v2.1.0`. Everything else comes from reading the code. Shadow-database restores have not been run, and nothing was run against a live cluster.

## Instructions for Antigravity

1. Amend `cli/plans/active/portable-backups-and-cloud-jump.md` to resolve **U1-U5**, and add them to its Review resolution table.
2. **Change the plan only in this pass.** Do not implement code, change ADRs, or touch a cluster. Do not edit files owned by the MinIO removal.
3. Re-check each cited line first. If a proposed fix is wrong, keep the requirement, record the alternative and its evidence, and flag it for the user.

## Blockers for Phase 1

### U1. The nightly job cannot hold its Lease
- **Evidence (Reproduced):** Only `alpine/k8s:1.34.1` has `kubectl`. Contract #5 requires renewal every 60 seconds with a 180-second TTL, but the plan acquires the Lease in `dump`, then runs `s3-fetch`, `s3-pack`, and `encrypt` sequentially with no renewer. `upload` is expected to release it from an image without `kubectl`. Any stage over 180 seconds lets another backup or a jump take the lock while the job is still running.
- **Required change:** Add one dedicated Lease-holder in `alpine/k8s`, as a native sidecar (`initContainers` with `restartPolicy: Always`), that acquires before `dump`, renews throughout, and releases on termination. Verify and state the minimum Kubernetes version this requires. A crashed job must recover through normal TTL expiry. Test contention, renewal past 180 seconds, and release.

### U2. Garage key permissions break CORS capture and restore
- **Evidence (Reproduced):** `backup-reader` with `--read` gets `AccessDenied` on `GetBucketCors`. `commons-admin` with the CLI's `--read --write` grant gets `AccessDenied` on `PutBucketCors`, and succeeds only after `--owner` is added. The plan tolerates only `NotImplemented`, so a nightly Garage run would either fail or silently lose CORS.
- **Required change:** Do not give `backup-reader` `--owner`. Define Garage bucket-configuration handling explicitly. Either capture it through an admin path available to the job, or record `cors: "not_captured"` per bucket in `index.json.enc` and `backup:status` without failing the object backup. For restore, define how CORS is applied, such as a temporary `--owner` grant removed afterward. Test with a real Garage bucket that has CORS configured.

## Required additions

- **U3. Hardcoded Garage RPC secret (add to Phase 0 item 1).** `rpc_secret` is a literal value in `cli/resources/views/k8s/plex/commons.blade.php:961`, and `cli/resources/views/k8s/garage/deployment.blade.php` also defines one, so clusters rendered from these templates share a secret committed to the repo. Only the workspace template defines a NetworkPolicy. Generate one secret per Garage deployment, store it in a Secret, preserve it across re-runs, and keep it out of ConfigMaps. Verify the supported Garage v2.1.0 mechanism (environment variable or secret file) before implementing. **Do not copy the value into plans, logs, or docs.**
- **U4. Garage key repair (add to Phase 0 item 1).** `ensureGarageBootstrap()` returns as soon as `commons-admin` exists (`cli/app/Commands/Plex/PlexInitCommand.php:392-394`). A crash after key creation and before saving `plex-admin` is therefore never repaired. If the key exists but `plex-admin` lacks valid credentials, recover them with `garage key info commons-admin --show-secret` and save them.
- **U5. Garage metadata assembly.** The `amazon/aws-cli` image has no `jq`, and starting one `aws` process per object is slow on large buckets while the job must keep its window bounded. Have `s3-fetch` write raw per-object responses as NDJSON, and assemble and validate `metadata.json` in `s3-pack`, where `jq` is available. Make timeouts and `emptyDir.sizeLimit` configurable, and fail as incomplete rather than truncating.


---

# Final Sign-Off Pass 5 Instructions for Claude

Antigravity has amended `cli/plans/active/portable-backups-and-cloud-jump.md` to resolve **U1–U5** in full and updated the **Review resolution table** (plan files only — no code or ADRs touched).

Please do a quick **Pass 5 final sign-off check** (do NOT edit code or ADRs; append your verdict under `# Final Sign-Off Verdict (Pass 5)`):
1. Verify **U1–U5** are cleanly resolved (`lease-holder` native sidecar on `alpine/k8s:1.34.1` requiring K8s >= 1.29; Garage CORS `cors: "not_captured"` on `backup-reader` + temporary `--owner` revoked in `finally` on portable capture/restore; Secret-backed generated Garage RPC secret removed from ConfigMaps; `ensureGarageBootstrap()` `commons-admin` crash-repair via `garage key info --show-secret`; NDJSON `s3-fetch` → `jq` assembly & validation in `s3-pack` with configurable `--timeout=` / `--work-limit=` failing incomplete rather than truncating).
2. Report **Final Verdict:** `APPROVED FOR EXECUTION (Phase 0+)` or any remaining blocker.

---

# Final Sign-Off Verdict (Pass 5)

## **APPROVED FOR EXECUTION (Phase 0+)**

U1-U5 are resolved at the design level, and no plan-level blockers remain. The MinIO removal has landed (`344ac3b8`), so all of Phase 0 can start. No further review pass is needed. The conditions below are binding implementation requirements for the phases named.

**Verified on disposable containers:** Garage `GARAGE_RPC_SECRET` through the environment and through `kubectl`-style exec, `commons-admin` secret recovery, temporary `--owner` CORS write and revoke, `backup-reader` read-only behavior, and AWS CLI output and key handling.

**Not yet proven:** the shadow-database restores, the native-sidecar Lease on a real cluster, and any full jump. Their planned tests and manual verification remain required before the related phases are called done.

## Binding implementation conditions

- **V1. Garage RPC secret (Phase 0 item 1).** Inject it with `GARAGE_RPC_SECRET` through `secretKeyRef`. Do not use a default Secret file mount: Garage refuses to start on mode `0644` (`File /run/rpc is world-readable`). Do not set `GARAGE_ALLOW_WORLD_READABLE_SECRETS`.
- **V2. Re-check citations (all phases).** The MinIO removal moved the plan's line references, for example `ensureGarageBootstrap()` is now at `PlexInitCommand.php:345` and the Garage commands at `StorageDriver.php:421,434,443,453`. The remaining `minio` strings in `resources/views/k8s/forgejo/forgejo.blade.php` are **Forgejo's own S3 driver name and env vars**. Do not remove them.
- **V3. `s3-fetch` metadata format (Phase 2).** `aws --output json` is pretty-printed (7 lines for one `head-object`), so its output is not NDJSON. Write a concatenated JSON stream and normalize it in `s3-pack` with `jq -c`, or strip raw newlines before appending.
- **V4. S3 key listing (Phase 2).** `--output text` split a key containing a newline into two lines. Use `list-objects-v2 --encoding-type url` and decode each key. Keep tab, newline, space, and Unicode keys in the real-Garage test.
- **V5. Garage nightly capacity (Phase 2).** Each `aws` process took about 1.1 seconds here. With one `head-object` and one `get-object` per object, that is roughly 37 minutes per 1,000 objects before transfer time. Add bounded parallelism or a faster metadata path, measure it, and have `backup:status` report when a bucket cannot finish inside `--timeout=`.
- **V6. Lease-holder failure behavior (Phase 2).** A `restartPolicy: Always` sidecar is restarted when it exits, so contention must be a bounded wait under `activeDeadlineSeconds`. If renewal fails or ownership is lost, the sidecar must write a marker to the shared `emptyDir`, and `upload` must refuse to write `manifest.json` when that marker exists.
