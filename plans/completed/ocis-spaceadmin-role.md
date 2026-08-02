# oCIS `ocisSpaceAdmin` Role + "New Space" Button Fix

## Problem

User cannot create a Space in Drive (`drive.luchtech.dev`, oCIS 8.0.6 on the
`larakube-159.89.205.239` cluster). The "+ New Space" button is gated by the
`CreateSpace` permission, which only the `admin` and `spaceadmin` roles carry.

## Diagnosis (verified live 2026-08-01)

The OIDC role pipeline is wired correctly end-to-end:

1. Zitadel Action `flattenOcisRoles` is attached to the Complement Token flow
   (type 2) on triggers 4 (`PreUserinfoCreation`) and 5 (`PreAccessTokenCreation`),
   so the `ocisRoles` claim IS embedded in the access token oCIS reads.
2. `admin@luchtech.dev` and `james@luchtech.dev` both hold an `ocisAdmin` grant
   on `LaraKube Shared Tools` (verified via the Management API).
3. Deployment: `PROXY_ROLE_ASSIGNMENT_DRIVER=oidc`,
   `PROXY_ROLE_ASSIGNMENT_OIDC_CLAIM=ocisRoles`, no mapping file set.
4. oCIS 8's DEFAULT role mapping (no `oidc_role_mapper` yaml needed):
   `ocisAdmin`→`admin`, `ocisSpaceAdmin`→`spaceadmin`, `ocisUser`→`user`,
   `ocisGuest`→`guest` (confirmed from oCIS proxy docs).
5. The claim value maps correctly — so the mapping was never the bug. The
   remaining suspects were a stale session/token (minted before the grant or
   the Action re-attach on 2026-07-31 22:23) or the claim context being empty.

Decision (user): skip the re-login test and ship the least-privilege role —
the purpose-built `spaceadmin` — beside full `ocisAdmin`, so Space creation
works without granting full system admin.

## Change

- `ClusterTool::ssoAdminRoles()` (DRIVE) now declares two roles:
  - `ocisAdmin` (unchanged label)
  - `ocisSpaceAdmin` — "create and manage Spaces, no system admin"
- `flattenOcisRoles` Action script (extracted to
  `InteractsWithZitadelApi::OCIS_ROLES_SCRIPT`) now picks the HIGHEST
  privilege present: `ocisAdmin` > `ocisSpaceAdmin` > `ocisUser` fallback
  (oCIS assigns exactly ONE role per user).
- `zitadelEnsureOcisRolesAction()` is now idempotently REFRESHING: if the
  Action exists but its script differs from `OCIS_ROLES_SCRIPT`, it pushes the
  new script via `PUT /management/v1/actions/{id}` (fieldMask
  `["name", "script"]`, tolerating Zitadel's "No Changes" 400). Re-running
  `sso:wire` converges existing clusters — previously an existing Action was
  silently skipped forever (AGENTS.md Idempotency standard).

## Tests

- `SsoWireCommandTest`:
  - existing-Action fakes now return the current `OCIS_ROLES_SCRIPT` (no PUT)
    and a body-aware `roles/_search` fake (the role search only inspects
    `result.0`, so a static response can't serve both roles);
  - create path asserts both `ocisAdmin` and `ocisSpaceAdmin` grants and the
    new script markers (`"ocisSpaceAdmin"`, `roles[0] !== "ocisAdmin"`);
  - new test: stale script → exactly one `PUT` to the Action, never a recreate.
- `SsoGrantCommandTest` / `SsoRevokeCommandTest`: unchanged and green
  (explicit `--role` and grant-based discovery are role-agnostic).

Verification: pint clean, phpstan no errors, pest 1187 passed / 5 skipped.

## Battle-test (operator, per AGENTS.md)

```bash
./build
larakube sso:wire --tool=drive                       # idempotent: refreshes Action, ensures ocisSpaceAdmin role
larakube sso:grant --tool=drive --role=ocisSpaceAdmin --email=james@luchtech.dev
```

Then hard log-out / log-in at `drive.luchtech.dev`:

- `admin@luchtech.dev` keeps `ocisAdmin` → claim `["ocisAdmin"]` → role `admin`
  → "+ New Space" appears.
- A user granted only `ocisSpaceAdmin` → claim `["ocisSpaceAdmin"]` → role
  `spaceadmin` → "+ New Space" appears without system admin.

If the button STILL does not appear on a fresh login with an `["ocisAdmin"]`
claim: temporarily set `OCIS_LOG_LEVEL=debug` on `drive-ocis` and watch the
proxy's `first matching role` line to confirm whether the claim is stuck at
`["ocisUser"]` (`ctx.v1.user.grants` empty at token creation — a separate
Action rework).

## Root cause found (2026-08-02) — `projectRoleAssertion` was off

The fallback case above is EXACTLY what was live. Evidence chain:

1. Fresh login at 2026-08-02T01:06:25Z (after the grant + script update),
   `signInActivity.lastSuccessfulSignInDateTime = 2026-08-02T01:43:39Z` for
   james@ — no stale session involved.
2. Browser `POST /api/v0/settings/assignments-list` returned role
   `d7beeea8-8ff4-406b-8fb6-ab2dd81e6b11` = plain `user` role (oCIS role IDs
   from ocis PR #5589; admin = 71881883-…, spaceadmin = 2aadd357-…).
3. The user's ID token decoded live shows `"ocisRoles": ["ocisUser"]` — the
   Action ran and took its EMPTY-grants fallback branch.
4. Zitadel source (v3 `internal/api/oidc/userinfo.go` + `internal/query/userinfo_by_id.sql`):
   `ctx.v1.user.grants` is loaded by
   `project_id = any(roleAudience)`, and `roleAudience` only includes the
   client's project when the project's **`project_role_assertion`** flag is on
   (or the scope explicitly requests role scopes — oCIS requests
   openid/profile/email only). `LaraKube Shared Tools` never had the flag —
   it was created by `zitadelEnsureProject()` without settings, while the RBAC
   project got it from `zitadelEnsureRbacProjectSettings()` (that's why
   `larakube_roles`/RBAC gating works and `ocisRoles` never did).

So the Action script, the grants, the flow/trigger attachment (flow 2,
triggers 4+5 — verified against the official trigger matrix) were all correct;
the grants list was empty AT RUNTIME because the project never asserted roles.

### Fix (command-driven, AGENTS.md)

- New `InteractsWithZitadelApi::zitadelEnsureSsoAdminProjectSettings()`:
  idempotent GET-then-PUT enabling `projectRoleAssertion` on the tool's
  project, preserving `name`/`projectRoleCheck`/`hasProjectCheck`
  (UpdateProject replaces the whole object). Deliberately does NOT enable
  `projectRoleCheck` — zero-role org members rely on the Action's `ocisUser`
  fallback to log in at all.
- `SsoWireCommand::ensureSsoAdminGating()` calls it before ensuring the
  Action/roles.
- Tests: all five Drive wire tests fake the project GET (and the PUT when the
  flag is missing); the `--admin-email` test asserts the PUT carries
  `projectRoleAssertion: true` and NO `projectRoleCheck`. 35 SSO tests green,
  pint clean, phpstan no errors.

### Battle-test (operator)

```bash
./build
larakube sso:wire --tool=drive          # flips projectRoleAssertion on LaraKube Shared Tools
```

Then hard log-out / log-in at `drive.luchtech.dev` (the flag applies at token
creation — existing sessions keep their stale claims until refresh/relogin):

- `james@luchtech.dev` (ocisAdmin+ocisSpaceAdmin) → claim `["ocisAdmin"]` →
  role `admin` → "+ New Space" appears.
- A user granted only `ocisSpaceAdmin` → claim `["ocisSpaceAdmin"]` → role
  `spaceadmin` → "+ New Space" appears without system admin.
- Zero-role members (ahled, elie, …) → claim `["ocisUser"]` → role `user` →
  unchanged.

Verify server-side without the browser: decode the ID token from the web flow
(`ocisRoles` should no longer be `ocisUser` for james@).
