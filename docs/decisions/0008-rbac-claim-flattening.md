# 0008 — Role-gated SSO via a claim-flattening Zitadel Action

**Status:** Accepted (2026-07-31)

## Context

`sso:wire`'s original OpenBao OIDC config wrote a single `user` role with
`policies=default,admin` and no `bound_claims` — every Zitadel user who
authenticated got full admin access to every secret, unconditionally. A wider
audit of `ClusterTool`'s other SSO-wireable tools found the same *shape* of
risk was possible for Grafana too: no gate of its own, so any org member could
log in (Grafana's own default landed non-elevated — Viewer — but "can log in
at all" was still open to everyone, which was the actual complaint: does a
non-technical member need Grafana?).

The obvious fix — gate login on a Zitadel project role, checked via OpenBao's
`bound_claims` and Grafana's `role_attribute_path` — hit a real blocker.
Zitadel's native roles claim (`urn:zitadel:iam:org:project:roles`) is a
**nested object** (`{"role-key": {orgId: orgDomain}}`), not a scalar or array
of strings. Neither mechanism can read that shape:

- OpenBao/Vault's `bound_claims` only matches scalar or list-of-string claim
  values — confirmed against `vault-plugin-auth-jwt`/`openbao-plugin-auth-jwt`
  source (`validateBoundClaims`), not assumed from docs alone.
- Grafana's `role_attribute_path` (JMESPath) has no documented way to test
  "does this key exist in a map value."

| Option | Fit |
|---|---|
| Match `bound_claims` / JMESPath directly against Zitadel's roles claim | Rejected — technically impossible given the claim's shape. |
| Per-tool Zitadel projects, gated solely by `projectRoleCheck` | Rejected as the *only* mechanism — gives a binary "in the project or not," not OpenBao's needed 3-tier admin/operator/auditor distinction. |
| A Zitadel Action that flattens role grants into a flat top-level claim | **Chosen.** |

## Decision

- One shared Zitadel project, **`LaraKube RBAC`** (`ClusterTool::rbacProjectName()`),
  holds every role-gated tool's roles, keyed `<tool>-<tier>`
  (`openbao-admin/operator/auditor`, `grafana-admin/editor/user`, …). Tools
  with no elevated-access story of their own (Vaultwarden, Forgejo, Outline,
  …) stay on the existing `LaraKube Shared Tools` project, untouched —
  `ClusterTool::requiresRbacGating()` is the switch.
- One org-wide Zitadel Action, `flattenLaraKubeRoles`
  (`zitadelEnsureRbacAction()`), attached to the Complement Token flow's two
  triggers — Pre Userinfo creation (4) and Pre access token creation (5).
  These trigger IDs are undocumented in Zitadel's REST reference; confirmed
  live via `GET /management/v1/flows/2`. The script reads
  `ctx.v1.user.grants` and writes a flat array claim, `larakube_roles`
  (e.g. `["openbao-admin", "grafana-user"]`), onto every token issued
  **org-wide** — Actions/Flows have no project or app scope in Zitadel, so
  this fires for every OIDC client, not just gated ones. It's a documented
  no-op for a user with zero grants (`if (ctx.v1.user.grants == undefined ||
  ...count == 0) return;`), so ungated tools are unaffected — they simply
  never read the extra claim.
- Each tool then gates itself independently against `larakube_roles`:
  - **OpenBao**: `bound_claims={"larakube_roles":"openbao-admin"}` per role.
    Works because Vault/OpenBao's claim matcher normalizes *both* sides to
    lists and checks for overlap — a scalar bound value legitimately matches
    inside an array-valued token claim (confirmed against
    `openbao-plugin-auth-jwt`'s `normalizeList`/`matchFound`).
  - **Grafana**: `GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_PATH`, a
    priority-ordered JMESPath chain — `contains(larakube_roles[*],
    'grafana-admin') && 'Admin' || contains(larakube_roles[*],
    'grafana-editor') && 'Editor' || contains(larakube_roles[*],
    'grafana-user') && 'Viewer' || ''` — plus
    `GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_STRICT=true`, which denies login
    outright (not a silent Viewer fallback) when no valid role is extracted.
    Verified live, not just from docs: a real no-role login produced
    Grafana's own "IdP did not return a role attribute" error.
- Zitadel's `projectRoleCheck` (a project-wide login gate, independent of
  either tool's own gate above) is turned on automatically by
  `ensureRbacGating()` the moment any tool is first wired to
  `LaraKube RBAC` — **not** left as a manual step, despite that being the
  original call earlier the same day. The initial reasoning was that
  auto-enabling it risked locking out a tool mid-rollout before any grants
  existed; that didn't survive being checked. OpenBao keeps its root-token
  auth, Grafana keeps local password login, and `sso:grant` itself
  authenticates as the machine PAT rather than an OIDC end-user login — none
  of those paths run through Zitadel at all, so nothing about this setting
  can actually lock an operator out. Once that was confirmed, deny-by-default
  from the moment a tool is wired is strictly better than open-until-
  manually-locked, so the earlier decision was reversed same-day.
- `sso:grant` / `sso:revoke` (`cli/app/Commands/Sso/`) manage the actual
  grants. `sso:revoke` is email-first — `multiselect()` over everything a
  user currently holds across every gated tool, not one role at a time —
  built for the "this account is compromised, pull everything now" case.

## Consequences

- ✅ Fixes the original vulnerability: OpenBao no longer grants unconditional
  admin to any authenticated user. Verified live, allow and deny, twice each.
  A "claim is missing" vs "claim doesn't match" wording anomaly appeared once
  on a retry of the same deny scenario — the *security outcome* (deny) was
  correct both times; root cause not confirmed. Flagged for follow-up —
  audit logging (still unbuilt) would help if it recurs.
- ✅ The pattern is reusable. A third role-gated tool needs only: its
  `rbacRoles()` entries on `ClusterTool`, its own bound_claims/JMESPath-
  equivalent gate in `oidcEnv()`, and nothing else — `sso:wire`'s
  `ensureRbacGating()` handles project registration, role creation, and
  Action attachment automatically.
- ⚠️ **The Action is org-wide.** Every existing SSO-wired tool's login now
  runs this script, gated tool or not. A bug in the script (or a wrong
  `allowedToFail` default) risks affecting logins cluster-wide. The script's
  robustness against edge cases beyond "zero grants" — machine/service-
  account logins, in particular — hasn't been exhaustively tested.
- ⚠️ **A real bug shipped, and this session's own hand-verification never
  caught it** — only actually running the built commands did.
  `zitadelEnsureRbacAction()`'s Zitadel `_search` calls sent PHP's
  `json_encode([])` — a JSON *array* — where Zitadel's API requires a JSON
  *object* (`{"queries":[]}`); Zitadel 400s on the array form. This silently
  broke the Action lookup on every `sso:wire` run since the code was written.
  It never surfaced because `ensureRbacGating()` was `void` and discarded the
  failure, and the Action already existed from manual setup done earlier in
  this work, so nothing depended on the broken call actually succeeding.
  Fixed: the body shape (both here and in the analogous
  `zitadelListProjectRoleKeys()`), and `ensureRbacGating()` now returns
  `bool`, short-circuits on the first failed step, and `sso:wire` aborts
  rather than wiring `bound_claims`/`role_attribute_path` against
  infrastructure that was never confirmed to exist. **Lesson:** hand-writing
  a correct curl body to verify a *mechanism* does not verify the *code*
  that's supposed to build that body — only running the actual command does.
- ❌ Deferred, not built: audit logging on OpenBao, MFA enforcement on
  `openbao-admin` holders. See `plans/active/openbao-hardening.md` for
  what's left.
