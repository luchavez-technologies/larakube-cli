# Plan: Cloudflare R2 OpenTofu Remote State & Stack Migration

**Status:** Proposed (Awaiting Approval)

## Context
`cli/resources/views/tofu/backend-s3.blade.php` and `InteractsWithOpenTofu::remoteStateConfig()` already implement OpenTofu S3-compatible remote state (`use_lockfile = true`, `skip_credentials_validation`, `skip_requesting_account_id`, `skip_metadata_api_check`, `skip_region_validation`, `skip_s3_checksum`), currently activated only via `LARAKUBE_TOFU_STATE_*` env vars. Cloudflare R2 derives S3 credentials directly from a Cloudflare API Token (`Access Key ID = token ID`, `Secret Access Key = sha256(token)`).

## Goals
1. Allow optional global R2 remote state in `~/.larakube/config.json` when a user's Cloudflare API token includes `Account → Workers R2 Storage → Edit`.
2. Prevent collisions with AWS cluster provisioning (`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`) by writing R2 S3 credentials to `~/.larakube/r2-credentials` (`0600`) and referencing `shared_credentials_files` + `profile = "r2"` in `backend-s3.blade.php`.
3. Protect existing local-state stacks (`~/.larakube/tofu/<stack>/terraform.tfstate` without `backend.tf`) from silently switching to empty R2 state on `cloud:scale` / `cloud:destroy`.
4. Add `larakube cloud:state:migrate` (and Desktop UI button) to upload existing encrypted local state into R2 via `tofu init -migrate-state -force-copy`.
5. Surface the optional 3rd permission card + toggle in Desktop `DnsTlsFields` (Create Server Step 3).

See artifact `implementation_plan.md` in conversation `c53a604a-9207-48ec-8f30-6745040c6190` for full file-by-file breakdown and verification plan.
