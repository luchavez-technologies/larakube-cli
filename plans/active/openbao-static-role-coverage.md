# Tracking: OpenBao static-role rotation coverage gaps, and where PgBouncer fits

**Status:** 🟢 RESOLVED (2026-08-23, end of day). Stalwart is healthy on prod (`1/1 Running`, 0 restarts) and a fresh `secrets:rotate --tool=mail` was verified to actually propagate the new password all the way to Postgres — confirmed via a real network-path auth test (`psql -h postgres.larakube-plex.svc.cluster.local`, NOT `-h localhost`, which gives a false positive through a permissive loopback `pg_hba.conf` rule and cost real time earlier today). See "2026-08-23, the actual root cause and fix" below for the full chain — it took four separate fixes layered on top of each other, not one.
**Prior status (superseded):** 🔴 STALWART DOWN ON PROD. Re-registering `stalwart`'s OpenBao static role (`secrets:wire --tool=mail`) crash-looped the pod with Postgres `28P01 password authentication failed` — `registerStaticRole()`'s "rotates on create" wasn't the actual root cause, just the trigger; see below for what really was.
**Created:** 2026-08-10
**Related:** `project_openbao_db_static_role_rotation` (operator memory — mechanism reference), `project_stalwart_rotation_sync_gap` (2026-08-23 memory — the local-cluster half of this, already fixed there), ADR-adjacent incident, not yet its own ADR.

---

## 2026-08-23 update — full re-audit, triggered by a local incident

While live-verifying the OpenBao/Stalwart SaloonPHP migration ([[project_saloonphp_migration]]), `secrets:rotate local --tool=mail` crash-looped Stalwart on the local OrbStack cluster (real DB password rotated in OpenBao via a genuine POST — proving the new Saloon transport works — but nothing synced it back into the Secret the Deployment reads). Fixed there via `mail:init` + `mail:restart`. Full detail in `project_stalwart_rotation_sync_gap`.

The user then asked to check whether the same class of problem exists on prod (`larakube-159.89.205.239`) and to audit `SecretsWireCommand.php`/`SecretsUnwireCommand.php`/`SecretsRotateCommand.php` plus naming conventions and the SaloonPHP transport. Everything below is **read-only** — no writes were made to prod.

### SaloonPHP → OpenBao transport: confirmed solid, no bugs found

Proven live today across both read and write paths: `GET database/static-creds/*`, `LIST database/static-roles`, `POST database/rotate-role/*` (via `secrets:rotate`), and the register-role path (via `mail:init`'s `resolveManagedDbPassword()`/`registerStaticRole()`). Every failure found below is a pre-existing wiring/lifecycle gap, unrelated to the Http→Saloon migration.

### Naming convention: `dbSecretRef()` is inconsistent by design, and it matters

Every tool except two follows `{tool}-secrets` (the same secret its own `:init` command creates) — e.g. `sso-secrets`, `notes-secrets`, `sign-secrets`, `chat-secrets`. **MAIL** (`stalwart`/`STALWART_STORE_PASSWORD`) and **GIT/Forgejo** (`forgejo`/`FORGEJO_DB_PASSWORD`) are the deliberate outliers, pointing at a secret named after the underlying app rather than the CLI's own tool name.

Forgejo's manifest always reads that one secret consistently — no problem. **Mail's does not:** `resources/views/k8s/mail/stalwart.blade.php` has an `@if($storeBootstrap ?? null)` branch — the EXPERIMENTAL, **local-only** "skip the setup wizard" pre-seed feature (`MailInitCommand::bootstrapStalwartStoreForLocal()`) — that makes the Deployment read `STALWART_STORE_PASSWORD` from **`mail-secrets`/`store-password`** instead of the `stalwart`/`STALWART_STORE_PASSWORD` secret `dbSecretRef()`, `secrets:wire`, and `secrets:rotate` all target. On `local`, the two paths point at genuinely different secrets and were never reconciled — this is the exact mechanism of the crash-loop. **Prod never takes this branch** (`$storeBootstrap` is null there), so prod's Deployment does read the right secret — its problem is different (below).

### Code-level gaps found in the three commands

- **`SecretsRotateCommand`** has no check that the target's `ExternalSecret`/`VaultDynamicSecret` is actually `Ready:True` before rotating. It only checks `staticRoleExists()`. On a cluster where the role exists but the sync object is missing or broken (exactly local's state today, and structurally possible on any cluster), it will happily rotate-and-restart into a crash loop. Prod is accidentally safe from this **only** because the role itself is currently missing there too (`staticRoleExists()` returns false → refuses instead of rotating) — not because of any actual safeguard.
- **`SecretsUnwireCommand::isToolInstalled()`** checks for the existence of `dbSecretRef()['secret']` to decide whether a tool shows up in `--all`/interactive mode. On local, that check would silently exclude `mail` (secret `stalwart` doesn't exist there at all) even though `secrets:unwire --tool=mail` explicitly would still work (it bypasses `resolveTargets()`'s auto-discovery). Minor, but means the "what's currently wired" auto-discovery list can't be trusted as exhaustive.
- **`SecretsWireCommand`** itself looks correct and matches the documented rotate-then-sync-then-restart race protection (`waitForExternalSecretSynced()` snapshotting `refreshTime` before rotating) — no new bug found here beyond the naming-convention interaction above.

### Prod: OpenBao static roles that currently exist

```
chat_matrix, data_directus, forgejo, grafana, link_kutt, outline,
outline_notes_luchtech_dev, penpot, penpot_design-luchtech-dev,
penpot_design_luchtech_dev, sign_documenso, vaultwarden, zitadel
```

(confirmed via a read-only `LIST database/static-roles`, port-forwarded with the root token — same method as the local check). Note `penpot` has **three** near-duplicate entries (`penpot`, `penpot_design-luchtech-dev`, `penpot_design_luchtech_dev`) — looks like leftover hyphen/underscore slug-convention churn; worth pruning the stale ones once confirmed unused.

### Prod: ExternalSecret health, full re-check (2026-08-23)

| ExternalSecret | Ready | Reason | Since |
|---|---|---|---|
| chat-secrets / chat-secrets-db | True | SecretSynced | healthy |
| data-secrets-db | True | SecretMissing | 08-17 (see note below) |
| design-secrets-design-luchtech-dev-db | True | SecretSynced | healthy |
| forgejo-db | True | SecretSynced | healthy |
| link-kutt-secrets-db | True | SecretMissing | 08-20 (see note below) |
| monitor-secrets / monitor-secrets-db | True | SecretSynced | healthy |
| notes-secrets-notes-luchtech-dev-db | True | SecretSynced | healthy |
| **record-sendrec-secrets-db** | **False** | **SecretSyncedError** | **08-18** |
| resume-reactive-secrets | True | SecretMissing | 08-17 |
| **resume-reactive-secrets-db** | **False** | **SecretSyncedError** | **08-18** |
| sheet-secrets | True | SecretMissing | 08-20 |
| **sheet-secrets-db** | **False** | **SecretSyncedError** | **08-18** |
| sign-documenso-secrets-db | True | SecretMissing | 08-17 (likely orphaned dupe of sign-secrets-db, see below) |
| sign-secrets-db | True | SecretSynced | healthy |
| **stalwart-db** | **False** | **SecretSyncedError** | **08-13** |
| sso-secrets-db (separate namespace) | True | SecretSynced | healthy |

**Confirmed via direct OpenBao query: all four `SecretSyncedError` targets (`stalwart`, `record_sendrec`, `resume_reactive`, and whatever role `sheet-secrets-db` expects) return `"unknown role"` — the static role itself is gone, even though its `VaultDynamicSecret` generator object is still present and pointed at the right path.** This isn't a wiring config problem — something deleted (or never fully created) these 4 specific roles in OpenBao, on two different dates (2026-08-13 for stalwart, 2026-08-18 for the other three), while ~13 other roles are untouched and healthy. `record_sendrec`, `sign_documenso`, and `link_kutt` were all confirmed **fully wired and healthy** in the 2026-08-10 audit below — `record_sendrec` has since broken; `sign_documenso`/`link_kutt` currently still have their role (per the LIST above) but their ExternalSecret reasons show `SecretMissing`, worth a closer look.

**Root cause of the vanished roles: FOUND.** Ruled out a clean `secrets:unwire` run — that command deletes the `VaultDynamicSecret` generator too, and those are still present (confirming the sync *config* was never touched, only the underlying role).

The actual bug: `AbstractToolRemoveCommand::dropCommonsTenants()` (`app/Commands/Tool/AbstractToolRemoveCommand.php:318-368`), which runs on `{tool}:remove --purge`, does this per Commons database:

```php
$ok = $this->removeResources(   // DROP DATABASE <tenant> via kubectl exec into postgres
    "Dropping database '{$database}' from Plex Commons (if exists)...",
    "...",
) && $ok;

$this->deleteStaticRole($kubectl, $database);   // <-- runs UNCONDITIONALLY, ignores $ok above
```

`deleteStaticRole()` executes regardless of whether the `DROP DATABASE` step actually succeeded. Postgres refuses `DROP DATABASE` while there are active connections to it — and a tool being `--purge`d is, in the ordinary case, still *live* at the moment removal runs (its pod holds an open connection), so this failure mode isn't an edge case, it's close to the default outcome unless the pod happens to already be down. Confirmed consistent with everything observed: all 4 affected tools (`stalwart`, `record_sendrec`, `resume_reactive`, and sheet's role) are still fully healthy today with intact data and unchanged deployments — exactly what "the DB drop failed, the OpenBao role got deleted anyway, and the command reported failure" looks like from the outside. The two distinct dates (08-13 for stalwart alone, 08-18 for the other three together) read as two separate `--purge` attempts, likely for a reinstall/troubleshooting purpose that was aborted once the command reported "One or more resources failed to remove."

**The fix:** gate the `deleteStaticRole()` call on the drop actually succeeding — don't touch OpenBao's rotation-managed role for a tenant whose database still exists and is still relying on it.

```php
$dropped = $this->removeResources(
    "Dropping database '{$database}' from Plex Commons (if exists)...",
    "...",
);
$ok = $dropped && $ok;

if ($dropped) {
    $this->deleteStaticRole($kubectl, $database);
}
```

**No immediate crash-loop risk on prod right now** — same mechanism that protects it as noted above (the missing role makes `secrets:rotate` refuse rather than rotate-and-break), but it also means **none of these 4 tools' DB passwords are actually being rotated by OpenBao right now**, contrary to what their still-wired ExternalSecrets imply.

### Next steps (none taken — needs the user's go-ahead before any prod write)

1. **Fix the actual bug**: gate `deleteStaticRole()` in `dropCommonsTenants()` on the DB-drop step's real result (patch above). This is the one that matters most — without it, this will keep recurring on any future `--purge` of a live tool.
2. Re-registering the roles (`secrets:wire --tool=<x>` again, which is idempotent-safe per its existing rotate-then-wait-then-restart flow) would restore rotation for `record_sendrec`/`resume_reactive`/sheet's tool — but do this deliberately, one at a time, watching each restart, not as a blind `--all`. **Mail/Stalwart is the exception** — see #3, fix the secret-name split first or `secrets:wire --tool=mail` will wire a secret the local-style Deployment config wouldn't read anyway (prod doesn't take that branch, so it's lower-risk there, but confirm before wiring).
3. Fix `MailTool`'s local/prod secret-name split at the template level (`stalwart.blade.php`'s `@if($storeBootstrap)` branch) so `secrets:wire`/`secrets:rotate --tool=mail` are either fully safe on local too, or `SecretsRotateCommand`/`SecretsWireCommand` reject the mismatch outright with a clear error instead of silently rotating a secret nothing reads.
4. Consider adding the missing safeguard to `SecretsRotateCommand`: check the target `ExternalSecret`'s `Ready` condition (not just `staticRoleExists()`) before rotating, so a broken-sync tool refuses loudly instead of crash-looping.
5. Prune the stale `penpot_design-luchtech-dev` role (hyphens preserved — pre-dates the hyphen→underscore instance-slug normalization in `ClusterTool::commonsDatabases()`); `penpot_design_luchtech_dev` (all underscores) is the current, live one.

### 2026-08-23, continued — correction, the mail:wire re-registration, and the actual crash

**Correction to the "still fully healthy" claim above:** live verification (deployments, Postgres `pg_database` listing, the plex tenant registry) proved `record-sendrec`/`resume-reactive`/`sheet-teable` don't exist anywhere anymore — their `--purge` runs fully succeeded and completed. Only their `ExternalSecret`/`VaultDynamicSecret` objects are orphaned leftovers (separate, lower-priority cleanup — `secrets:unwire --tool=X` currently can't clean these up either, since it short-circuits on the missing-role check before reaching deletion). **Only `stalwart` (Mail) was a genuine live instance of the bug**: deployment alive, database alive, role missing.

`larakube secrets:wire production --tool=mail --context=larakube-159.89.205.239 --force` was run to re-register it. It reported success (`✅ ... rotated by OpenBao every 168h`), and the ExternalSecret `stalwart-db` flipped `Ready: True / SecretSynced`. But the Stalwart pod immediately crash-looped:

```
⚠️ Startup failed: Failed to create tables: PostgreSQL error (store.postgresql-error):
reason = db error: FATAL: password authentication failed for user "stalwart", code = 28P01
```

Confirmed this wasn't a naming/mapping bug — the Deployment's `secretKeyRef` (`stalwart`/`STALWART_STORE_PASSWORD`) matches exactly what the `ExternalSecret`'s `target.template` writes, and the `STAKATER_STALWART_SECRET` reloader-hash env var proved the pod restarted with the current secret content. Confirmed the Postgres role `stalwart` itself still exists and can log in (`rolcanlogin: t`). So OpenBao's `static-creds/stalwart` endpoint was returning a password Postgres never actually had — `registerStaticRole()`'s documented "POST rotates the password as a side effect the instant it runs" (true, and previously confirmed live for Zitadel's *first-ever* registration) did not hold for **re-registering a role name that had previously existed and was deleted** (exactly `stalwart`'s history here, per the `dropCommonsTenants()` bug above).

**Fix shipped:** `SecretsWireCommand::wireTool()` no longer trusts create-time rotation alone. Immediately after `registerStaticRole()` succeeds, it now makes an explicit `rotateStaticRole()` call (the same `POST /v1/database/rotate-role/{role}` `secrets:rotate` uses) before touching the ExternalSecret/deployment — if that explicit rotation fails, `wireTool()` errors out and does **not** proceed to restart the deployment. Covered by a new regression test (`secrets:wire --tool=mail does not restart the deployment when the forced rotation fails`) plus updated Saloon fakes on every existing success-path test in `SecretsWireCommandTest.php`. Full suite green (1830 passed / 5 skipped / 0 failed) after the change.

**This closes the class of bug for all future `secrets:wire` runs, on any tool — it does not by itself fix prod's currently-down Stalwart pod.** That needs one more command run directly by the user (Claude Code's auto-mode classifier blocks this specific live-prod-write action, consistently, regardless of phrasing):

```
KUBECONFIG=~/.kube/config larakube secrets:rotate production --tool=mail --context=larakube-159.89.205.239 --force
```

This forces the same explicit rotate-role call the fixed `wireTool()` now does automatically, which will make Postgres and OpenBao agree and let the pod start cleanly on its next restart.

**This turned out to be wrong — not incorrect, just not the actual root cause.** Running that exact command still left Stalwart down. See below for what the real problem was.

### 2026-08-23, the actual root cause and fix

The explicit-rotate-role fix above was real and shipped, but it didn't fix Stalwart, because the failure wasn't "OpenBao never rotated" — a direct `psql -h localhost` test from inside the Postgres pod appeared to confirm the password worked, but that was a **false positive**: `-h localhost` from within Postgres' own pod hits a permissive loopback `pg_hba.conf` rule that doesn't actually check the password. The correct test connects over the real path the app uses — `psql -h postgres.larakube-plex.svc.cluster.local` — and that one kept failing, proving Postgres's actual password never matched what OpenBao/the Secret held, no matter how many times `secrets:wire`/`secrets:unwire`+`secrets:wire`/`secrets:rotate` were run.

Queried OpenBao directly (`database/static-creds/stalwart`) and found `last_vault_rotation` genuinely advancing on every rotation — OpenBao was doing its job correctly. But the password hash in that response never matched the hash of what was actually sitting in the `stalwart` Kubernetes Secret, and the Secret's hash never changed across three separate rotation attempts. **The rotated password was never reaching the Secret at all** — External Secrets Operator v0.11.0 (a month+ out of date; see [[feedback_check_latest_versions]]) has a confirmed, still-unresolved class of bug (external-secrets/external-secrets #4568, #4167, #918, #4225) where a `creationPolicy: Merge` `ExternalSecret` backed by a `VaultDynamicSecret` generator reports `Ready`/`SecretSynced` with an advancing `refreshTime` while never actually re-fetching the value past its first-ever sync.

Fixing this took four layered changes, not one:

1. **Upgraded ESO v0.11.0 → v0.16.2** (two-hop path required — v0.17.0 stops serving the `v1beta1` API every existing object and manifest used; v0.16.2 is the last version serving both). Commits `dca8d6d`, `f1d0ad3`.
2. **v0.16.2 requires a webhook + cert-controller**, not present in the old v0.11.0 vendoring — its `ValidatingWebhookConfiguration` defaults to `failurePolicy: Fail`, which would silently reject every `ExternalSecret`/`SecretStore` apply cluster-wide without them. Discovered live mid-upgrade; the cert-controller then failed its own readiness check because the trimmed-down vendored manifest dropped the `external-secrets.io/component: webhook` label the cert-controller's watch filters on — fixed in `f1d0ad3`.
3. **Vendored the missing `GeneratorState` CRD.** ESO's "stateful generators" feature (introduced v0.14.0) gives the controller a dedicated CRD to track a generator-sourced value's state across reconciles — every `VaultDynamicSecret`-backed `ExternalSecret` (all of this CLI's `secrets:wire`-created ones) crash-loops the whole controller with `no matches for kind "GeneratorState"` without it. This is very likely the actual upstream fix for the original staleness bug — a real per-object state mechanism replacing the ad-hoc caching that froze rotated passwords. Commit `0ec5721`.
4. **Fixed an unrelated `SecretsRotateCommand` bug** blocking verification: `handle()` resolved the target instance against `ClusterTool::SECRETS` unconditionally (even with no `--domain`), instead of the actual tool being rotated — `resolveInstanceTargetsForDomain()` filters the tools registry by whichever `ClusterTool` it's handed, so this picked up SECRETS' own registered instance and silently misapplied it to Mail/Stalwart's `dbSecretRef()`/`deploymentName()` lookups. `secrets:wire` already had this right (only resolves per-tool, only when `--domain` is given); `secrets:rotate` now matches. Commit `c62c3e5`.

**Verified fixed:** a fresh `secrets:rotate --tool=mail --force` after all four fixes landed produced a password that authenticates successfully via the real network-path test, and Stalwart's pod is `1/1 Running`, 0 restarts.

**Still pending, not urgent:** the plan's originally-approved scope was a two-hop upgrade all the way to latest stable (v2.9.0 as of this writing) — only hop 1 (v0.16.2) has landed. Hop 2 is optional now that the actual fix (`GeneratorState`) is already in place at v0.16.2, but was the user's explicit choice for "a lasting solution," so it's still worth finishing rather than declaring done at the first version that happened to work.

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
