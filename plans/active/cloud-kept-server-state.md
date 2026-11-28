# LaraKube Cloud keeps server state by default (ADR 0044)

**Status:** Approved by the user 2026-11-28; not started.

## Why
"State storage backend" is the second thing a non-developer must set up before the first server, and it means nothing to them. Cloud keeps it for them instead: encrypted, durable, movable.

## Today (Cloud)
- `servers.state_backend_id` is a required FK to `state_backends` (team's bucket, endpoint, region, keys in OpenBao at `vault_path`).
- `StoreServerRequest` requires it.
- `OperationLauncher::inputs()` reads `$server->stateBackend` for `LARAKUBE_TOFU_STATE_BUCKET`/`ENDPOINT`/`REGION` and the keys (now `LARAKUBE_TOFU_STATE_ACCESS_KEY_ID`/`SECRET_ACCESS_KEY`), plus `LARAKUBE_TOFU_STATE_ID` = server id and `LARAKUBE_TOFU_PASSPHRASE`.
- `servers/create.tsx` step 1 has the "State storage backend" select.

## Steps
1. **Setup (the user):** a private R2 bucket per Cloud environment (`larakube-cloud-state-staging`, `larakube-cloud-state-production`) and an R2 API token limited to that bucket (Object Read & Write). Add these to each environment's settings in Desktop:
   - `LARAKUBE_PLATFORM_STATE_BUCKET`
   - `LARAKUBE_PLATFORM_STATE_ENDPOINT` (`https://<account>.r2.cloudflarestorage.com`)
   - `LARAKUBE_PLATFORM_STATE_ACCESS_KEY_ID` and `LARAKUBE_PLATFORM_STATE_SECRET_ACCESS_KEY` (secrets)
2. **Cloud data:**
   - `servers.state_backend_id` becomes nullable; null means Cloud keeps the state.
   - Config `larakube.platform_state` reads the four settings; "available" means all four are set.
3. **Launcher:** when `state_backend_id` is null, use the platform bucket and keys. Everything else (state id, passphrase, encryption) is unchanged.
4. **Create page:**
   - Remove the storage select from the main path. When the team has no storage of its own and platform state is available, send nothing.
   - Under "Advanced: keep the server's records in my own storage", show today's select.
   - If platform state is not configured, fall back to requiring the team's own storage (today's behaviour) and say why plainly.
5. **Request validation:** `state_backend_id` is optional when platform state is available, required otherwise; still team-scoped.
6. **Move to my own storage:** an action on the server page (and in a "leaving Cloud" flow).
   - Cloud copies the state object from the platform bucket to the team's bucket over the S3 API, verifies the copy, points the server at the team's backend, then deletes the platform copy.
   - A Cloud operation, idempotent, safe to retry.
7. **Passphrase export:** "Download my servers' recovery details", a JSON per team with, per server, the name, provider, state location and state passphrase. Re-authentication is required, it is audit-logged, and its wording explains it is a secret.
8. **The LaraKube CLI:** document reading Cloud-exported state (`LARAKUBE_TOFU_STATE_*` + passphrase) with `cloud:destroy`/`cloud:create`; no code change expected.
9. **Tests:**
   - launcher env for null vs team backend;
   - request validation both ways;
   - create page props;
   - the move operation (S3 calls faked; copy verified before switching; the platform copy deleted only after);
   - export authorization and audit.

## Order
Steps 2–5 first (removes the step from the create page). Then 6 and 7, before the first real customer leaves.
