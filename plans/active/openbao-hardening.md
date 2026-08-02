# Plan: OpenBao Authorization Hardening

**Status:** Core hardening **shipped 2026-07-31** for OpenBao and Grafana,
verified live in both directions (allow/deny) for both tools. The mechanism
that made this possible ended up different from the original plan below —
see **[docs/decisions/0008](../../docs/decisions/0008-rbac-claim-flattening.md)**
for the actual design and why the naive approach didn't work. What's left:
audit logging, MFA enforcement, and deciding on `projectRoleCheck` — see the
checklist at the bottom.

**Why this mattered:** OpenBao held (and will increasingly hold, per
`plans/active/openbao-database-secrets.md`) the Plex Commons database root
connections. Every Zitadel user who logged into OpenBao got the `admin`
policy unconditionally — a compromise of OpenBao's OIDC login leaked **all**
database root passwords.

---

## Original Threat Model (as found, 2026-07-30)

| Attack vector | Protection at the time | Risk |
|---------------|-------------------------|------|
| Any Zitadel user logs into OpenBao | `policies=default,admin` on role `user`, no `bound_claims` | **Critical** — full `secret/*` access |
| Any Zitadel user logs into Grafana | No gate at all — Grafana's own default (Viewer) happened to be non-elevated, but nothing stopped access | **Medium** — dashboards/metrics visible to anyone in the org, including non-technical members with no need for it |
| Stolen Zitadel session token | No MFA | **Critical** — mint an OpenBao token |
| Leaked root token (`openbao-bootstrap`) | Stored in a K8s Secret | **High** — operator mistake exposes it |

The originally-planned fix (`bound_claims` matched directly against Zitadel's
project-roles claim) turned out to be **technically impossible** — that claim
is a nested object, and neither OpenBao's `bound_claims` nor Grafana's
`role_attribute_path` can read into it. ADR 0008 covers the real mechanism
(a Zitadel Action that flattens grants into a flat `larakube_roles` claim)
and is the authoritative reference from here on — this file only tracks
remaining work.

---

## What shipped

- **OpenBao**: three roles (`admin`/`operator`/`auditor`), each gated by
  `bound_claims` against `larakube_roles`, `default_ttl=30m`, `max_ttl=4h`,
  `max_age=3600`. `default_role` removed. The old unconditional-admin `user`
  role is unconditionally deleted on every `sso:wire` run (not
  migration-detected — see `wireOpenBaoOidc()`).
- **Grafana**: three roles (`grafana-admin`/`grafana-editor`/`grafana-user`),
  mapped via a priority-ordered `role_attribute_path` JMESPath chain to
  Admin/Editor/Viewer, with `role_attribute_strict=true` denying login
  outright for anyone holding none of them.
- **`LaraKube RBAC`** Zitadel project + the org-wide `flattenLaraKubeRoles`
  Action — see ADR 0008.
- **`sso:grant`** / **`sso:revoke`** (`cli/app/Commands/Sso/`) — grant/revoke
  role-gated access. `sso:revoke` is email-first: shows everything a user
  holds across every gated tool and lets you pull some or all of it in one
  pass, built for the "this account is compromised" case.
- **`ensureRbacGating()`** in `SsoWireCommand.php` now fails loudly — a
  broken Action or role write aborts `sso:wire` rather than silently wiring
  `bound_claims`/`role_attribute_path` against unconfirmed infrastructure
  (this exact failure mode shipped once and was caught only by running the
  real command — see ADR 0008's consequences).
- **`projectRoleCheck`** — turned on automatically by `ensureRbacGating()`
  the moment any tool is first wired to `LaraKube RBAC`, not left as a
  manual step. The earlier "might lock out users mid-rollout" worry didn't
  survive scrutiny: OpenBao keeps root-token auth, Grafana keeps local
  password login, and `sso:grant` itself authenticates as the machine PAT —
  nothing Zitadel-side can lock an operator out, so deny-by-default from the
  start beats open-then-manually-lock. Confirmed live via a project
  settings read-back (`projectRoleAssertion: true, projectRoleCheck: true`).

Verified live end-to-end, twice each direction, for both tools: a granted
role gets the correct policy/org-role; an ungranted role gets denied. Test
coverage: `SsoWireCommandTest.php`, `SsoGrantCommandTest.php`,
`SsoRevokeCommandTest.php` — including a mutation-tested confirmation guard
on `sso:revoke` and a mutation-tested `bound_claims` payload assertion.

---

## Checklist

| # | Task | Status |
|---|------|--------|
| H1 | Write per-tier policies (`admin-policy`, `operator-policy`, `auditor-policy`) | ✅ Shipped |
| H2 | Write per-tier OIDC roles with `bound_claims`, TTLs | ✅ Shipped (via the Action, not raw `bound_claims` on Zitadel's own claim — see ADR 0008) |
| H3 | Remove `default_role`, enforce re-auth via `max_age` | ✅ Shipped (`max_age` lives on the *role*, not `auth/oidc/config` — real bug found and fixed, see ADR 0008) |
| H4 | Zitadel-side role provisioning (project + roles) in `sso:wire` | ✅ Shipped as `ensureRbacGating()`, generalized to any `requiresRbacGating()` tool, not just OpenBao |
| H5 | Old→new migration detection | **Deliberately not built** — CLI is pre-release with one cluster; per the standing "no one-time migration code" rule, the old role is unconditionally deleted every run instead of migration-detected |
| H6 | Audit device enablement | ❌ Not started |
| H7 | ADR documenting the auth model | ✅ [docs/decisions/0008](../../docs/decisions/0008-rbac-claim-flattening.md) |
| H8 | Tests: all roles auth correctly, unauthorized users rejected | ✅ Shipped, plus live verification beyond what the plan asked for |

### Remaining work

1. **Audit logging (H6).** Enable OpenBao's `file` audit device (PVC or S3
   sink). Would also help diagnose a one-off anomaly hit during live
   testing — a deny that logged as "claim is missing" once and "claim
   doesn't match" on retry, same scenario, same account. The *security
   outcome* was correct both times (denied either way); the root cause of
   the wording difference was never confirmed, and there's currently
   nothing to look back at if it recurs.
2. **MFA enforcement on `openbao-admin` holders.** The original plan assumed
   Zitadel *groups* would gate this; the shipped design uses project-role
   *grants* instead (`sso:grant`), so this needs redesigning against the
   actual grant model, not copied from the original wording. Likely a
   Zitadel Login Policy / project-level MFA requirement scoped to
   `LaraKube RBAC`, not an org-wide setting.
3. ~~`projectRoleCheck`.~~ **Shipped 2026-07-31** — see above.
