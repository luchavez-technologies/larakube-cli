# Remove legacy category `ClusterTool` cases

## Status: NOT STARTED — scoped and safety-verified only, execution deliberately withheld pending review

## Why this exists

The user confirmed the naming-convention migration (ADR 0012/0021, the move to
per-tool `ToolCategory` tagging) is complete, and asked that the 29 legacy
"category verb" `ClusterTool` cases (`GIT`, `NOTES`, `FLOW`, `MONITOR`, `SIGN`,
`MAIL`, `CHAT`, `VPN`, `SSO`, `SECRETS`, `CRM`, `DATA`, `DASHBOARD`, `DESIGN`,
`PASTE`, `ANALYTICS`, `MEET`, `DNS`, `DRIVE`, `ERRORS`, `INSIGHTS`, `LINK`,
`PASSWORDS`, `RECORD`, `SHEETS`, `SUPPORT`, `TASKS`, `UPTIME`, `WEBMAIL` — the
full, exact list from `ClusterTool::isLegacy()`) can now be deleted, since they
were only ever "Retained as deprecated aliases for backwards compatibility
with production clusters" (the enum's own comment).

The user explicitly offered to do this themselves while awake, and asked
whether this could be done unsupervised overnight instead. This document is
the result of that investigation.

## Live-data safety check — DONE, conclusive

A deleted backed-enum case makes `ClusterTool::from()`/`tryFrom()` return
null/throw for any persisted string still using it. Checked every known
kubectl context, read-only, before any code change:

- `larakube-159.89.205.239` (luchtech-vps, the most tool-dense real cluster,
  86 days old): `configmap/plex-registry` (`larakube-plex` ns) — every tenant
  uses its canonical product stem. `secret/larakube-tools-registry`
  (`larakube-shared` ns, NOT a ConfigMap) — every `"tool":` value is a
  canonical product name (stalwart, bulwark, matrix, forgejo, vaultwarden,
  ocis, zitadel, headlamp, livekit, grafana, twenty, outline, netbird,
  documenso, n8n, openbao). **Zero legacy category words anywhere.**
- `larakube-13.250.105.252`, `larakube-3.1.217.1`,
  `larakube-devbox-test-dev-box`: no `plex-registry` ConfigMap exists; tools
  registry is empty (`[]`) or absent. Nothing to break.
- `orbstack` (local): same — empty/absent.

**Conclusion: no live cluster anywhere has a persisted legacy-category
string.** Deleting the enum cases will not cause `::from()` to throw against
any real data this CLI manages today. This part is safe and does not need
redoing.

## Why execution was NOT attempted tonight

This is a genuinely large, two-layered refactor, not a simple dead-code
deletion — confirmed by reading `app/Enums/ClusterTool.php` (2332 lines) in
full and grepping external references:

1. **Inside `ClusterTool.php` itself**, the legacy cases are paired alongside
   their canonical case in ~15 separate `match()` arms, not isolated in one
   place: `vendor()`, `getLabel()`, `icon()`, `brandName()`, `service()`,
   `rbacRoles()`, `ssoAdminRoles()` (DRIVE only), `supportsNoPlex()`,
   `supportsMultipleInstances()`, `hasInstanceAwareRemoval()`,
   `resourceNaming()`, `legacyCategoryPrefix()`,
   `oidcPostLogoutRedirectUris()`, plus `isLegacy()`/`canonicalTool()`/
   `category()`/`withoutCategory()` themselves. Several of these (`rbacRoles`,
   `hasInstanceAwareRemoval`, `supportsMultipleInstances`, `resourceNaming`)
   are long multi-case arms — easy to drop the wrong one by hand. PHP enums
   have no "unused case" warning; a stray `self::MAIL` left anywhere after the
   `case MAIL = 'mail';` line is deleted is an immediate fatal error
   (undefined enum case), which is at least loud, but only once every
   reference is actually exercised — `composer analyse`/a full `composer test`
   run is the only way to be sure none are missed, not a visual scan.

2. **External references are large and NOT all internal/dead.** Grepped
   `ClusterTool::{CASE}` directly (not through `canonicalTool()`) per legacy
   case across `app/`, `tests/`, `resources/`:

   | Case | Files | Case | Files | Case | Files |
   |---|---|---|---|---|---|
   | VPN | 26 | CHAT | 25 | SSO | 23 |
   | MAIL | 23 | SECRETS | 22 | DATA | 19 |
   | GIT | 19 | FLOW | 16 | MEET | 15 |
   | DRIVE | 15 | MONITOR | 15 | NOTES | 15 |
   | CRM | 14 | SIGN | 13 | ANALYTICS | 12 |
   | ERRORS | 12 | PASSWORDS | 11 | LINK | 11 |
   | TASKS | 10 | UPTIME | 10 | DESIGN | 10 |
   | PASTE | 10 | SHEETS | 9 | SUPPORT | 9 |
   | WEBMAIL | 9 | DNS | 8 | INSIGHTS | 8 |
   | RECORD | 8 | DASHBOARD | 8 | | |

   That's ~400 file-touches with overlap — realistically 150-250+ unique
   files. These are not all legacy/back-compat code paths: they include
   current command classes, Blade manifests, and tests that use e.g.
   `ClusterTool::MAIL` as the live identity for "the mail tool" in
   actively-maintained code, not a compatibility shim.

3. **User-facing CLI surface, not just internal plumbing.** Confirmed via
   `grep -rl "ClusterTool::tryFrom\|ClusterTool::from("`: `tool:init
   --tool=`, `tool:show --tool=`, `tool:alias`, `mail:wire`/`:unwire`,
   `secrets:wire`/`:unwire`/`:rotate`, `sso:prune` all parse a user-supplied
   string directly through `ClusterTool::tryFrom()`. Today, `--tool=mail`,
   `--tool=git`, `--tool=sso`, etc. all still work (they resolve to the
   legacy case, which internally redirects via `canonicalTool()`). Deleting
   the case outright means those flag values stop parsing — a genuine
   backwards-incompatible change to what operators can type, not just an
   internal rename. Whether any current docs, muscle memory, or scripts rely
   on the category spelling (vs. the product name) is a judgment call, not
   something grep alone resolves.

Given (1) a single missed match-arm is a silent crash risk the very next time
that code path runs against a live customer cluster, and (2) the external
scope requires per-site judgment (is this a dead back-compat path, or the
tool's live identity today?) rather than a mechanical find-and-replace, this
was judged too large and too consequential to execute unsupervised, with no
checkpoint for review before it could ship. This matches the user's own
standing preference for incremental, reviewed changes over one large bundled
one ([[feedback_incremental_changes]] in memory).

## Recommended approach, once picked back up

1. **Per-case, not all-29-at-once.** Each legacy case is an independent,
   revertible unit of work: remove its entry from every match arm in
   `ClusterTool.php`, delete the `case X = '...'` line, then fix every
   external `ClusterTool::X` reference that surfaces (`composer analyse` will
   catch the PHP-level breaks; `composer test` will catch behavioral ones).
   Commit after each case (or small batch of low-risk ones) passes
   format+analyse+test, per the repo's existing "commit when a feature/fix is
   done" convention — not one 150-file commit.
2. **Decide the `--tool=` flag-value question explicitly before touching
   code**, not as a side effect of the enum edit: either (a) accept that
   `--tool=mail`/`--tool=git`/etc. stop working the moment the case is
   deleted (a `BREAKING CHANGE:`/`feat!:` per ADR 0025, since it deletes a
   flag value), or (b) keep a small explicit `--tool=` alias map in
   `ToolInitCommand`/`ToolShowCommand`/etc. that translates the old spelling
   to the canonical one at the command boundary, independent of the enum.
   This needs the user's call — it is a product decision (what operators are
   allowed to type), not a code-safety one.
3. **Order by reference count, lowest first**, as the safest warm-up before
   tackling VPN/CHAT/SSO/MAIL (26/25/23/23 files): DASHBOARD, DNS, INSIGHTS,
   RECORD (8 files each) are the smallest, lowest-risk starting points.
4. Re-run the live-cluster check from this document again immediately before
   actually deleting cases, in case a cluster state changed between now and
   then.

## Critical files

`app/Enums/ClusterTool.php` (the enum itself — ~15 match arms to edit per
case removed), everything found by `grep -rln "ClusterTool::{CASE}"` for
whichever case is being removed, `app/Commands/Tool/ToolInitCommand.php` /
`ToolShowCommand.php` / `ToolAliasCommand.php` (the `--tool=` flag-value
question), ADR 0025 (commit type/versioning for whatever is decided).
