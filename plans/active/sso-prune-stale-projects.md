# Plan: `sso:prune` — Detect & Remove Orphaned Zitadel Projects

Status: active · Created 2026-08-25

## Incident that motivated this

Live DX bug 2026-08-24/25 on `larakube-159.89.205.239`: a role grant was made on
Zitadel project **`forgejo`** (`387127298416967780`) while the real Forgejo app had
been silently moved to **`git-forgejo`** (`387711458479177871`) by the 2026-08-20
per-tool-project RBAC redesign (`rbacProjectName()` = `deploymentName()`). The old
project kept its old app + roles forever because `sso:unwire` only deletes the app
tracked by the `sso-app-{tool}` Secret — which after re-wiring pointed at the NEW
app. Result: users granted on the legacy project were denied login by
`projectRoleCheck` ("Login not possible. The user is required to have at least one
grant on the application. (APP-asb43)").

## Verified live state (2026-08-25, read-only probes)

| Check | Result |
|---|---|
| `sso-app-git` Secret tracks | new project `387711458479177871` / app `387711462606373007` |
| Forgejo login_source ClientID | `387711462606438543` (new app) |
| References to OLD clientId `387127303685079140` anywhere in cluster Secrets | **NONE** |
| User grants on stale project | **0** |
| Legacy-named projects among all 16 | only `forgejo` |

⇒ The stale project is safe to delete today; every other live project name matches
the current `deploymentName()` scheme.

## Goal

One idempotent CLI verb that finds Zitadel projects no longer consumed by ANY live
tool config and removes them safely — so renamed/redesigned wiring never strands
state again.

## Command design

```
larakube sso:prune {environment=local}
  {--context=    : kube-context}
  {--project=*   : explicit candidate id(s)/name(s); skips interactive pick}
  {--force       : non-interactive confirmation; requires --project=}
```

File: `cli/app/Commands/Sso/SsoPruneCommand.php`

### Detection (read-only)

1. `POST /management/v1/projects/_search` → all projects.
2. Protected set (never prunable): `ZITADEL`, `LaraKube Shared Tools`, plus every
   shipped tool's current `rbacProjectName()` across discovered instances
   (instance discovery may start conservative: unnamed + `-{$domain}` slugs seen
   in `sso-app-*` secret names).
3. Live-consumer set of clientIds:
   - `client-id` key of every `sso-app-*` Secret in `larakube-sso`
   - `client-id`/`client-secret` keys of every `*-oidc` Secret across
     `larakube-*` namespaces
   - Forgejo `login_source.cfg.ClientID` values (DB probe via the
     `su-exec git forgejo admin auth` exec path used by `applyCliOidc()`)
4. A project is PRUNABLE iff: not protected AND **every** app's clientId is
   absent from the live-consumer set. (ClientId membership is the primary gate;
   the name heuristic is display-only context.)

### Removal

`DELETE /management/v1/projects/{id}` (cascades apps + grants inside Zitadel).

### UX / standards

- Laravel Prompts `table` for the candidate report (name, id, #apps, #grants),
  then `multiselect`/`confirm`; never Symfony table/choice.
- Non-interactive rule: without a TTY, require `--force` AND `--project=…`
  together, else throw `MissingFlagException` — no silent defaults.
- Print an audit line per deletion (`pruned project {name} ({id}), cascaded N apps`);
  second run prints "no orphaned projects" and exits 0 (idempotent).
- Refuse (exit 1, no changes) if any selected project suddenly fails the
  clientId gate at execution time (re-checked immediately before DELETE).

## Trait additions (`InteractsWithZitadelApi`)

- `zitadelListProjectApps(string $host, string $pat, string $projectId): array`
- `zitadelDeleteProject(string $host, string $pat, string $projectId): bool`
- `zitadelCountProjectUserGrants(...): int` (display-only; best effort — v1 path
  `/projects/{id}/users/grants/_search` verified working on this build)

## Tests (`tests/Feature/SsoPruneCommandTest.php`)

1. Happy path: one stale project listed + confirmed + deleted; protected and
   live-referenced projects untouched.
2. A project whose app clientId appears in an `sso-app-*` Secret is skipped even
   if its name looks legacy.
3. Re-run after prune → "no orphaned projects", exit 0.
4. Non-interactive without `--force --project=` → `MissingFlagException`.
5. `--project=` naming a protected/live project → refusal exit 1.

All `Http::fake()` for Zitadel calls, `Process::fake()` for kubectl/exec probes
(ADR 0019 conventions; unique fixture helper name `ssoPruneFakes()`).

## Rollout (this instance)

1. `./build`
2. `larakube sso:prune production --context=larakube-159.89.205.239`
3. Expect exactly one candidate: `forgejo (387127298416967780)`; confirm; retry
   the nexa-web.site login afterwards as regression proof.

Stopgap until the verb ships: deleting the project by hand in the Zitadel console
is equally safe today (0 grants, 0 live references) — operator-run UI action, not
an ad-hoc system edit.

---

## Status update 2026-08-25

**Phase 1 (this plan) — SHIPPED**: `sso:prune` implemented as designed, with one
simplification discovered while building: the per-project apps fetch and
user-grant count were dropped. The `sso-app-*` Secret's `project-id` key alone is
the authoritative live-reference set (every wire path writes it), so candidate
detection needs no Zitadel app enumeration at all — fewer API calls, and a
smaller blast radius if a listing endpoint ever changes shape.

Files:
- `app/Commands/Sso/SsoPruneCommand.php` — detection gate, --project= narrowing,
  MissingFlagException guard, per-delete re-validation against a freshly-read
  reference set
- `app/Traits/InteractsWithZitadelApi.php` — zitadelListAllProjects(),
  zitadelDeleteProject()
- `app/Http/Integrations/Zitadel/Requests/ListProjectsRequest.php` +
  `DeleteProjectRequest.php`
- `tests/Feature/SsoPruneCommandTest.php` — 7 cases: exact-target deletion,
  id-or-name matching, refusal of live/unknown targets, idempotent no-op,
  both missing-flag guards, refuse-to-prune-blind

Verification: pint clean, phpstan clean, full suite 1879 passed / 5 skipped.

**Phase 2 — PENDING (wire/unwire integration)**: warn inside sso:wire when
creating a project while an unreferenced legacy sibling exists; optional
--delete-project on sso:unwire reusing SsoPruneCommand's gate helpers (extract
referencedProjectIds()/protectedProjectNames() into a shared trait then).
