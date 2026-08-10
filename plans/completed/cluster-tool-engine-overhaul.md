# Plan: ClusterTool Category/Engine Decoupling — Fix Compound-Tool & Multi-Engine Wiring Bugs

**Status:** ✅ BUILT — Stages A, B, C shipped 2026-08-10. Full test suite (1633 passed, 0 failed) and full-project `phpstan` clean at every stage.

## What this actually is, vs. the original proposal

This plan started as a pasted doc (`cluster_tool_engine_overhaul_plan.md`) proposing a new `app/Engines/AbstractToolEngine` class hierarchy plus a from-scratch backup redesign. Neither shipped as originally written:

- **The class hierarchy was replaced** with a `HasWorkloadComponents` contract implemented directly on the `ClusterTool` enum — mirroring `DatabaseDriver`/`CacheDriver`/`StorageDriver`'s existing pattern (small contracts on one flat enum), which is this codebase's actual precedent for "one enum, many backends." A parallel `HasEngineVariants`/`ClusterToolEngineData` DTO layer was also dropped mid-build: it would have meant rewriting DATA's already-correct, well-tested per-engine ternaries for no behavioral gain.
- **The backup section was mostly already shipped** before this plan started (per-item S3 objects, manifest-committed-last) — only the dynamic PVC discovery sub-goal was real, new work (Stage C below).
- The actual bug driving this effort, per the user: *"it all started with you making a lot of mistakes with compound cluster tools like N8N/Windmill and PocketBase/Directus. The *:wire logic are crazily handled because of this anomaly."*

## Stage A — Component representation (closes compound-tool drift)

**New:** `app/Enums/ClusterToolComponentRole.php`, `app/Data/ClusterToolComponentData.php`, `app/Contracts/HasWorkloadComponents.php`.

`ClusterTool::components(?instance, ?engine): list<ClusterToolComponentData>` is the single source of truth for a tool's sub-deployments — CHAT (synapse/cinny/coturn/db), GIT (server/runner), and DESIGN (backend/frontend/exporter) get real multi-component entries; ~26 other tools get one PRIMARY. `deploymentName()` now delegates to `primaryComponent()->deployment` (verified byte-identical output for every tool via `tests/Unit/ClusterToolComponentsTest.php`).

This replaced two previously independent, drift-prone representations: the Blade manifest (source of truth for what's actually deployed) and a hand-copied `kubectl delete` resource-list literal in each `{Tool}RemoveCommand::teardown()`. `ChatRemoveCommand`/`GitRemoveCommand`/`DesignRemoveCommand::teardown()` now iterate `components()` via a new `AbstractToolRemoveCommand::teardownComponentsCommand()` helper.

DESIGN's one-off `oidcEnv()` key `frontend_deployment` (the only prior acknowledgment that a tool could have a second component at all) became the general `also_patch` list, derived from any component with `sharesPrimarySecret: true` — `SsoWireCommand`'s two consumers of it are now a `foreach`, not a one-tool special case.

## Stage B — Engine-resolution bug fixes (the actual reported bug)

**New:** `app/Traits/ResolvesToolEngine.php` — one `resolveInstanceEngine()` helper replacing **four separate, mutually inconsistent** ad-hoc engine-detection copies found across `SsoWireCommand` (CHAT+DATA only), `SsoUnwireCommand` (FLOW only, via a hardcoded `flow-windmill` probe), `MailWireCommand` (CHAT only), `MailUnwireCommand` (FLOW only, differently again). Algorithm: registry `InstanceData->engine` as a hint, confirmed live via `deploymentExists()` before trusting it (mirrors `DataRemoveCommand`'s existing "registry is informational, never authoritative" discipline) → live-probe every candidate engine → prompt (or `MissingFlagException` non-interactively) only when genuinely ambiguous.

**Real bugs closed, each with a regression test:**
- `SecretsWireCommand`, `MailWireCommand`, `MailUnwireCommand`, `SsoWireCommand`, `SsoUnwireCommand` no longer default a PocketBase-only DATA instance to Directus's `dbSecretRef()`/`commonsDatabases()`/`smtpEnv()`/`oidcEnv()` shape.
- `SsoWireCommand`'s old DATA engine check queried the whole namespace by label selector with zero instance scoping — a second DATA instance's PocketBase Deployment could make it wrongly conclude the *main* Directus instance was PocketBase too. Now instance-scoped (`tests/Feature/SsoWireCommandTest.php`, "not contaminated by...").
- `ClusterTool::baseDeploymentName()` for FLOW was `'flow-n8n'` unconditionally, ignoring `$engine` — confirmed against the real Blade manifests that `flow-windmill` is a genuinely different Deployment. `productName()`/`commonsDatabaseList()`/`smtpEnv()` had the same gap. Fixed; Windmill's own SMTP/SSO env-var schema stays deferred (explicit user decision) — `smtpEnv('windmill')` now correctly returns `null` (refuse) instead of silently targeting `flow-n8n`.
- `MailUnwireCommand::unwireTargets()` computed `$engine` and never passed it to `smtpEnv()` — a second, previously-undiscovered instance of the same bug class.
- `sso:wire`, `sso:unwire`, `secrets:wire`, `vpn:wire` migrated to `--domain=` (ADR-0012), mirroring `AbstractToolShowCommand`. `mail:wire`'s pre-existing `--instance=` and `mail:init`'s were deliberately left as-is — MAIL is an architectural singleton (`supportsMultipleInstances() === false`), so renaming would be cosmetic churn closing no bug.
- Deleted ~200 lines of dead code in `MailWireCommand` (an orphaned `unwireTargets()`/`unwireSynapseSmtp()` copy, unreachable since `mail:unwire` is its own command class) that my edit would otherwise have left calling an undefined method.

## Stage C — Dynamic PVC backup discovery

**New:** `ClusterTool::forDeployment(string): ?array{tool, component}` — reverse lookup (exact match across every tool/component/engine first, so e.g. `forgejo-runner` is never mistaken for an instance-suffixed copy of `forgejo`; instance-suffix prefix match as fallback, longest-base-wins).

`InteractsWithBackup::backupVolumeTargets(string $kubectl)` replaced its hardcoded 7-entry array with live discovery: every `larakube-*` namespace's Deployments, resolved via `forDeployment()`, kept only when the matched component has `backupVolume: true`. The three previously-commented exclusions (Prometheus, webmail, Synapse's bulk media/site-packages) now hold declaratively — Prometheus is simply never matched (not a `ClusterTool`), webmail's component doesn't opt in, Synapse's component's `backupPath` is scoped to the signing key file only. SeaweedFS (Plex Commons infrastructure, not a `ClusterTool`) stays one explicit entry.

A `LEGACY_VOLUME_NAMES` map preserves the exact `name` string each of the 6 previously-hardcoded entries had (`openbao`, `forgejo`, `drive-ocis`, `vaultwarden`, `stalwart`, `synapse-identity`) — a backup taken before this shipped is looked up by that name on restore (`BackupRestoreCommand::restoreVolume()`), so changing it would have silently stranded every pre-existing backup. New components without a legacy name default to `{tool}-{component-key}`.

All 5 call sites (`BackupRunCommand` ×2, `BackupScheduleCommand`, `BackupUnscheduleCommand`, `BackupRestoreCommand`) updated to pass `$kubectl`; `tests/Feature/BackupCommandTest.php`'s shared `backupFakes()` helper extended with a realistic live-namespace/deployment fixture so all 56 existing tests (plus new ones) hold under the new discovery mechanism.

## Verification

Every stage: `./php vendor/bin/pint` clean, full-project `./php vendor/bin/phpstan analyse app/` clean (0 errors), full `./php vendor/bin/pest` suite passing (1633 passed / 5 pre-existing skips / 0 failed, up from a 1614-passed baseline). New/extended test files: `tests/Unit/ClusterToolComponentsTest.php`, `tests/Unit/ClusterToolFlowEngineTest.php`, `tests/Unit/ClusterToolForDeploymentTest.php`, `tests/Feature/{Chat,Git,Design}RemoveCommandTest.php` (new), `tests/Feature/{Sso,SsoUnwire,Mail,MailUnwire,Secrets,Vpn}WireCommandTest.php` (extended), `tests/Feature/BackupCommandTest.php` (extended).

## Deliberately out of scope

- Full per-instance VPN middleware naming for NOTES (would also need `notes/ingress.blade.php`'s hardcoded middleware-name literal fixed — a separate, narrower pre-existing gap, not touched).
- Windmill's own SMTP/OIDC environment-variable names (net-new integration research, explicitly deferred).
- `MailInitCommand`'s `--instance=` → `--domain=` rename (cosmetic-only for a singleton tool).
