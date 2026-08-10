# Tracking: OpenBao static-role rotation coverage gaps, and where PgBouncer fits

**Status:** 🟡 TRACKING — one incident resolved today, real near-term risk remains on at least one more tool.
**Created:** 2026-08-10
**Related:** `project_openbao_db_static_role_rotation` (operator memory — mechanism reference), ADR-adjacent incident, not yet its own ADR.

---

## The incident (2026-08-10)

`sso-zitadel` went `1/2 Ready` in production. Root cause, fully traced:

OpenBao auto-rotates the `zitadel` Postgres static role every `168h` (7 days) — that part is working exactly as designed. But `sso-secrets` (the Kubernetes Secret Zitadel actually reads its DB password from) was only ever populated **once**, at `sso:init` time (`SsoInitCommand.php`'s one-shot `registerStaticRole()` + `readStaticRolePassword()` + patch-secret flow). Nothing re-syncs it when OpenBao rotates later. The static role rotated on 2026-08-09 23:51 UTC; `sso-secrets` kept last week's password until manually corrected today.

Fixed live: read OpenBao's current value via `bao read database/static-creds/zitadel` (non-mutating), patched `sso-secrets` to match, restarted `sso-zitadel`. Confirmed healthy (`2/2 Running`, clean logs, `sso.luchtech.dev` → `HTTP 302`).

**This is not Zitadel-specific.** Any tool whose Postgres credential was provisioned via the one-shot `registerStaticRole()`/`readStaticRolePassword()` pattern (rather than continuously synced via `secrets:wire`'s `VaultDynamicSecret`) has the same silent-drift exposure — it just hasn't hit its 7-day rotation at an inconvenient moment yet.

## Current coverage, checked live against the cluster (2026-08-10)

OpenBao has **7 static roles** registered (`bao list database/static-roles`): `link_kutt`, `penpot`, `record_sendrec`, `sign_documenso`, `stalwart`, `vaultwarden`, `zitadel`.

`ClusterTool::dbSecretRef()` — the gate `secrets:wire` uses to decide what it *can* target — currently covers only **6 tools**: `DATA`, `SIGN`, `RECORD`, `SSO`, `LINK`, `DESIGN`.

Live `VaultDynamicSecret` resources (`kubectl get vaultdynamicsecret -A`) — the actual continuous-sync mechanism — exist for only **3**: `link-kutt-secrets-db`, `record-sendrec-secrets-db`, `sign-documenso-secrets-db`.

| Tool | OpenBao static role? | `dbSecretRef()` eligible? | Actually wired (`VaultDynamicSecret`)? | Risk |
|---|---|---|---|---|
| LINK (Kutt) | ✅ | ✅ | ✅ | None — fully covered |
| RECORD (Sendrec) | ✅ | ✅ | ✅ | None — fully covered |
| SIGN (Documenso) | ✅ | ✅ | ✅ | None — fully covered |
| **SSO (Zitadel)** | ✅ (rotated 2026-08-09) | ✅ | ❌ | **Just fired.** Fixed manually today; will recur every 7 days until wired. |
| **DESIGN (Penpot)** | ✅ (rotated 2026-08-09, TTL ~161h39m as of this check) | ✅ | ❌ | **Same bug, ~6.7 days out** (≈2026-08-16). Same silent-drift exposure as SSO, not yet fired. |
| DATA (PocketBase/Directus) | ❌ (no static role registered at all) | ✅ | ❌ | Not yet onboarded to OpenBao rotation — lower urgency (static password, not drifting), but means it's still on a manually-set one-time password with no rotation at all. |
| STALWART (mail) | ✅ | ❌ — **no `dbSecretRef()` entry** | ❌ | Rotating in OpenBao already, `secrets:wire` literally cannot target it — enum gap, not just "not yet run." |
| VAULTWARDEN (passwords) | ✅ | ❌ — **no `dbSecretRef()` entry** | ❌ | Same as Stalwart — rotating already, unreachable by `secrets:wire`. |

## What to actually do

1. **Immediate (this week, before 2026-08-16):** `larakube secrets:wire production --tool=design` for Penpot — same command shape that already works for `sign`/`record`/`link`. This is the one with a real clock on it.
2. **Soon:** `larakube secrets:wire production --tool=sso` — close the exact gap that just bit us, so the manual fix doesn't have to repeat in another 7 days.
3. **Enum fix required first:** add `dbSecretRef()` entries for `STALWART` and `VAULTWARDEN` in `ClusterTool.php` (mirror the existing `SIGN`/`RECORD`/`LINK` shape) before `secrets:wire --tool=webmail`/`--tool=passwords` can even be attempted — right now the command would just reject them ("does not have a Commons database password OpenBao can rotate"), even though OpenBao is already silently rotating their passwords underneath.
4. **Lower priority:** decide whether `DATA` should get an OpenBao static role registered at all (currently on a static, non-rotating password — safe from *this* bug, but not benefiting from rotation either).
5. **Verification step for all of the above:** after wiring, confirm a `VaultDynamicSecret` resource actually appears (`kubectl get vaultdynamicsecret -A`) — that's the concrete signal continuous sync is live, not just that the command exited 0.

## Where PgBouncer fits in (it doesn't, directly — but it's what surfaced this)

PgBouncer was a **separate, unrelated** piece of work (another agent, same day): pooling the shared Plex Postgres connection via a transparent `postgres` Service repoint (`commons.blade.php`, config-driven by `spec.services.postgres.pooler.enabled`). It had its own real bug — `edoburu/pgbouncer:v1.25.2-p0` configured with `auth_type=scram-sha-256` + `auth_query` (dynamically fetching each user's stored SCRAM hash from `pg_shadow`) failed SASL auth even with a **verified-correct** password and a correctly-SCRAM-formatted stored hash. Root cause of *that* specific failure was not fully isolated (confirmed it's a PgBouncer-layer issue, not Postgres/credentials) before it was disabled as a mitigation.

The two issues layered on top of each other and briefly looked like one bug: PgBouncer's SASL failure was the *visible* symptom, but disabling PgBouncer alone didn't fix Zitadel — that's when the real, independent OpenBao-sync gap surfaced with its own (different) Postgres-native `28P01` error.

**Current state:** pooler disabled via `larakube plex:resources production` (`postgres` Service back to `app: postgres` directly; `pgbouncer` Deployment/Service deleted). Re-enabling it is a separate follow-up — needs the SCRAM `auth_query` failure actually root-caused first (candidates worth checking: whether `edoburu/pgbouncer`'s image genuinely supports full SCRAM-over-`auth_query` relay for non-admin users despite exposing the env var, or whether `AUTH_QUERY` needs to run over a specific connection/database context this setup didn't provide). Not tracked further in this doc — this doc is about the OpenBao rotation-coverage gap specifically.
