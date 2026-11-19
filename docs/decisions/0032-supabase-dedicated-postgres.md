# 0032: Dedicated Per-Instance `supabase/postgres` for Supabase

**Status:** Accepted

## Context

Most LaraKube Cluster Tools lease a database on Plex Commons shared PostgreSQL (`InteractsWithPlex::leaseDatabase()`). Supabase's stack (GoTrue Auth, PostgREST, Realtime, Storage API, Meta, and Supavisor) requires superuser-owned internal schemas (`auth`, `storage`, `realtime`, `_analytics`, `_supavisor`), custom roles (`supabase_auth_admin`, `supabase_storage_admin`, `authenticator`, `anon`, `authenticated`, `service_role`), `pgsodium`, and `pgvector`, initialised by upstream SQL bootstrap scripts (`docker/volumes/db/*.sql`). Sharing a general-purpose Commons PostgreSQL instance would either fail role/extension ownership checks or risk cross-tenant privilege escalation.

## Decision

1. **Dedicated per-instance `StatefulSet`**: `supabase:init` deploys one `supabase/postgres` `StatefulSet` (`supabase-db-<instance>`) per `--domain` instance with its own `5Gi` data PVC (`supabase-db-<instance>`) and `1Gi` `pgsodium` key PVC (`supabase-pgsodium-<instance>`), using upstream `supabase/postgres:15.8.1.085` and upstream `db/*.sql` init scripts.
2. **Commons S3 & SMTP only**: Supabase still uses Plex Commons SeaweedFS for Storage buckets (unless `--no-plex` is set, which creates a dedicated Storage PVC instead) and Commons/existing SMTP wiring via `HasSmtpWiring`. It **never** leases a Commons PostgreSQL database or Commons Redis tenant.
3. **`pgvector` enabled out of the box**: `supabase:init` runs `create extension if not exists vector with schema extensions;` after rollout so AI/embedding workloads work immediately.
4. **Backup boundary**: `backup:run` currently backs up Plex Commons PostgreSQL and explicit allow-listed tool volumes (ADR 0024). Dedicated `supabase-db-<instance>` PVCs are **not** included in `backup:run` yet; operators must snapshot PVCs separately until volume-level database backup support lands.

## Consequences

- Running multiple `--domain` Supabase instances gives each one isolated Postgres state, JWT/anon/service-role keys, and S3 bucket.
- `supabase:remove` preserves `supabase-db-*`, `supabase-pgsodium-*`, and `supabase-storage-*` PVCs plus `supabase-secrets-*` by default, deleting them only when `--purge` is passed.
- Memory footprint is higher than single-binary tools (~2.3 GiB full stack, ~1.5 GiB core-only), reflected in `ToolFootprint` so `ClusterCapacityAssessor` warns or refuses on undersized nodes (with PocketBase recommended as the lightweight alternative).
