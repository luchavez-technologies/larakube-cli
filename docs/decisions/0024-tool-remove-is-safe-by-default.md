# ADR 0024: `*:remove` Teardown is Safe-by-Default

## Status
Accepted (2026-08-05)

## Context
Previously, running `larakube <tool>:remove` (or the legacy `<tool>:init --remove`) dropped the tool's Plex Commons database and released logical Redis indexes by default unless the operator remembered to pass `--keep-data`. A single forgotten flag would result in irreversible data loss. Additionally, object storage (S3) buckets were never deleted during remove commands, creating an inconsistent lifecycle state where databases were wiped while S3 files were orphaned.

## Decision
1. **Safe-by-Default Behavior**: `larakube <tool>:remove` now preserves all persistent data by default. Bare `*:remove` deletes Kubernetes workloads (Deployments, Services, Ingresses, Secrets) while preserving the Commons Postgres tenant, logical Redis index, and S3 storage bucket.
2. **Opt-in Data Destruction (`--purge`)**: Data destruction is explicitly opt-in. Operators must pass `--purge` to drop the Plex Commons database and release logical Redis indexes.
3. **Permanent S3 Bucket Preservation**: S3 object storage buckets are NEVER deleted automatically, even when `--purge` is specified, eliminating unintentional asset loss.
4. **Transparent Reattachment**: Re-running `larakube <tool>:init` after a default `*:remove` automatically reattaches to existing databases and S3 buckets with preserved data intact.

## Consequences
- Operators can safely tear down and re-deploy tools without risking accidental data loss.
- Destructive actions require explicit intent (`--purge`).
