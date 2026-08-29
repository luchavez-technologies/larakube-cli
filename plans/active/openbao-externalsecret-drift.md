# OpenBao ↔ ExternalSecret drift: roles that no longer exist

**Status:** deferred, not started. Diagnosis below is verified live on
`larakube-159.89.205.239`, 2026-08-28.
**Related:** `plans/active/openbao-static-role-coverage.md` (marked RESOLVED for Stalwart
only — these four were in its table and were never fixed), memory
`project_openbao_db_static_role_rotation`.

## The mechanism is fine

`SecretsWireCommand::wireTool()` is correct and needs no change. It snapshots
`refreshTime` before rotating, forces a rotation so OpenBao and Postgres agree, applies the
ExternalSecret, nudges reconcile, **waits for a real sync before restarting**, and sets
`reloader.stakater.com/auto=true` so later rotations restart the pod.

Supporting components are current: ESO **v0.16.2** (well past the v0.11.0 caching bug),
Reloader **v1.0.69** running with 13 deployments annotated, OpenBao 2.6.1.

## The failure is state drift

Live OpenBao holds 14 static roles. Three ExternalSecrets read roles that **do not exist**:

| ExternalSecret | Reads role | In OpenBao? |
|---|---|---|
| `record-sendrec-secrets-db` | `record_sendrec` | absent |
| `resume-reactive-secrets-db` | `resume_reactive` | absent |
| `sheet-secrets-db` | `sheet_teable` | absent |

All three report `could not get secret data from provider`, `Ready=False`,
`SecretSyncedError`, with **`refreshTime` frozen at 2026-08-18** — ten days stale at time of
writing. A fourth, `resume-reactive-secrets`, fails the same way against a KV path
(`production/RESUME_DB_PASSWORD`).

A separate, benign class refreshes normally but reports `SecretMissing` — orphans pointing
at removed sources: `data-secrets-db`, `link-kutt-secrets-db`, `sheet-secrets`,
`sign-documenso-secrets-db`. Harmless, but they pad the failure list and hide real breakage.

Role naming is still churning: it is now `forgejo_git_luchtech_dev` and
`stalwart_send_luchtech_dev` (recorded as `forgejo`/`stalwart` five days earlier), and
`penpot` still has three near-duplicates — `penpot`, `penpot_design-luchtech-dev`,
`penpot_design_luchtech_dev`.

## Why it matters

`SecretsRotateCommand` has no check that the target's ExternalSecret is `Ready:True` before
rotating — the gap the previous plan identified and left open. Running `secrets:rotate` on
any of these three would rotate the password in Postgres and restart the pod into a `28P01`
crash loop. Prod is only accidentally safe because `staticRoleExists()` returns false and
refuses first.

Nothing surfaces this. Four of 21 ExternalSecrets have been broken for ten days silently.

## Work, in order

1. **Guard `secrets:rotate`** — refuse when the target's ExternalSecret is not
   `Ready:True`/`SecretSynced`. Pure code; closes the crash-loop path.
2. **Surface the drift** — report "ExternalSecret reads a static role that does not exist"
   somewhere visible. `secrets:show` is the natural home.
3. **Re-wire the three** — `secrets:wire --tool=record|resume|sheet` creates the missing
   roles through the correct rotate → sync → wait → restart path. Restarts prod pods, so
   this is an operator decision, not something to automate into a fix-up command.
4. **Prune** the orphaned ExternalSecrets and the duplicate `penpot` roles once confirmed
   unused.

## VPN is already wired for this

`VpnTool` now implements `HasRotatableDatabasePassword`, so `secrets:wire --tool=vpn` works.
Note the ordering risk when it is used: NetBird is what every `--vpn-only` ingress depends
on, so a broken rotation chain takes down the dashboards you would use to diagnose it. Do
items 1 and 2 before wiring VPN on a cluster that matters.
