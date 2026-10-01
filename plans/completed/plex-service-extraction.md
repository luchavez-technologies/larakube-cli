# Plan: Extract `PlexService` — the first trait→service strangler

**Status:** ✅ Phases 1–4 done (Phase 1 `5a90d48`, Phase 2 `2214393`, Phases 3–4 after the gate by explicit decision). `PlexJoinCommand` holds its own `PlexService`; the other commands still set `$plexContext`. Created 2026-09-09, revised 2026-09-11.
**Scope:** `InteractsWithPlex` ONLY. This is a pilot, not a programme.
**Shape:** stateful service, constructed directly (decided 2026-09-11 — see Design).

> **Prerequisite (done, `2f0c18f`):** fix the live-DNS flake in
> `tests/Unit/InteractsWithGlobalConfigProcessTest.php` FIRST. Every phase below
> is verified by "full suite green, zero test edits"; two tests that flap on a
> resolver blip make a red suite ambiguous and the gate worthless.

---

## Why this one, and why now

Measured 2026-09-11:

| | |
|---|---|
| Traits in `app/Traits` | **131** |
| `InteractsWithPlex` | 1478 lines, **45 methods**, holds state (`$this->plexContext`) |
| Files composing `InteractsWithPlex` | **55** |
| Files ASSIGNING `$this->plexContext` | **49** (56 assignment sites) |

Several hundred methods are reachable on `$this->` inside a command like
`UpCommand`, with no declared origin. That is not an aesthetic complaint — it is
the mechanism behind three real defects found in one day (2026-09-09):

1. **`installComponents()` unreachable.** `GeneratesProjectInfrastructure` CALLED
   it; `InteractsWithArchitecturalEngine` DEFINED it; 22 commands composed the
   first without the second. `nextjs:new` was simply the first to reach that
   line, and it died with "method does not exist".
2. **A duplicate `isPlexBacked()` was nearly added** to `ConfigData` because the
   existing one was invisible among its sibling methods.
3. **Commons provisioning was copy-pasted into four scaffolders** (`new`,
   `wordpress:new`, `statamic:new`, `nextjs:new`) plus `up`. Three of them
   carried the same half-finished wiring; `up` minted a second tenant under a
   different env suffix that nothing ever connected to.

Plex is the right pilot: a clear domain boundary (tenant lifecycle + Commons
lifecycle), the most methods of any trait, real state, and it produced those
bugs — so the value shows up immediately or not at all.

**Correction (2026-09-11):** the first draft said the context was "set by
assignment from five different commands". The real figure is **49 files, 56
sites**. That is an order of magnitude worse than assumed, and it is the single
strongest argument for this work — but it also means the shim below is
mandatory, not a convenience.

## Non-goals

- **No big-bang conversion.** The other 130 traits stay traits until this pilot
  proves itself. Explicitly stop and evaluate at the exit gate below.
- **Presentation/plumbing traits stay traits forever** (`LaraKubeOutput`,
  `StreamsProcessOutput`, `InteractsWithOs`). The test is: *does it hold domain
  logic AND state, and get composed into many commands?* By that test roughly
  eight of the 131 qualify, not all of them.
- **No behaviour change.** This is a move, not a redesign. Any bug found on the
  way gets its own commit.
- **No call-site churn during phases 1–4.** All 55 composing files keep calling
  `$this->method()` untouched.

## Design

### Considered and rejected

**Laravel Actions** (`lorisleiva/laravel-actions` v2.12.0 — verified 2026-08-25,
supports `illuminate/contracts ^13.0`, so compatibility was never the issue).
Rejected on fit, not quality:

- Its value is one class serving as controller *and* job *and* listener *and*
  command. This is a CLI with one entry point; three quarters of the package
  would go unused.
- Wrong granularity: of the 45 methods, ~8 are units of work. The rest
  (`plexTenantIdentifier`, `plexBucketName`, `buildDropTenantSql`) are pure
  functions that do not want to be classes. We would need a service for those
  AND actions on top — more concepts, not fewer.
- `asCommand` would fight the existing command layer (the
  `{tool}:{action} {environment} --flag` shape enforced by `CommandShapeTest`,
  plus `RequiresFlagsWhenNonInteractive`, `ConfirmsDestructiveAction`,
  `LaraKubeOutput`) and win nothing.
- Its test sugar (`Action::mock()`) pulls against ADR 0019, which fakes at the
  **shell** boundary because for this CLI the interesting behaviour IS the
  kubectl invocation.

Still viable later, additively, for those ~8 orchestration steps. This decision
does not close that door.

**Stateless service + explicit `PlexTarget` parameter** — briefly chosen on
2026-09-11, reversed the same day once measured. The case for it was that an
explicit target prevents a silent mismatch when one run touches two clusters.
No such run exists: the only three files that assign `plexContext` more than
once (`PlexInitCommand`, `PlexShowCommand`, `PlexResourcesCommand`) do so in
mutually exclusive branches resolving a single value. The argument then inverts
— constructor state makes "one target per run" structural, whereas a per-call
parameter lets a later edit pass a different target on call three than call one.
Its only real gain, container auto-resolution of a no-arg class, buys a
type-hint in a CLI that will never swap the implementation, and buys nothing at
all while the shim is in place.

### Chosen: run-scoped state in the constructor

Ordinary OOP: the context is fixed once per run and valid for the object's
lifetime, which is exactly what constructor state models.

```php
final class PlexService
{
    /**
     * The Commons namespace is an invariant, not configuration — one
     * definition, never overridden, never passed. A constant, so the 30
     * literals scattered across 14 files have somewhere to converge.
     */
    public const NAMESPACE = 'larakube-plex';

    public function __construct(private readonly ?string $context = null) {}
}
```

`plexNamespace()` stays on the trait as a one-liner returning
`PlexService::NAMESPACE`. **Repointing the other ten files at the constant
(`InteractsWithBackup`, `SyncsClusterSecrets`, `SupportedDriversTrait`, the four
Backup commands, `SheetTool`, `PasswordTool`) is a SEPARATE commit, after the
exit gate.** A phase billed as a pure move cannot quietly touch fourteen files.

If the namespace ever genuinely needs to vary — a second Commons on one cluster
— add a parameter to the specific call then, with the real case in hand.

### The shim is what makes this incremental

`InteractsWithPlex` keeps the ambient state, so none of the 49 assignment sites
move during this pilot:

```php
trait InteractsWithPlex
{
    protected ?string $plexContext = null;

    protected function plex(): PlexService
    {
        return new PlexService($this->plexContext);
    }

    // Retained, delegating, one line each:
    protected function allocateDatabase(DatabaseDriver $driver, string $tenant, string $password): bool
    {
        return $this->plex()->allocateDatabase($driver, $tenant, $password);
    }
}
```

The shim lets the extraction land in reviewable pieces without a 55-file diff,
and it can be deleted later, per-method, as callers move to `$this->plex()->…`
naturally. If the exit gate says stop, the shim is the only thing to unwind.

### Method grouping (the 45, as they actually cluster)

| Group | Needs the context? | Notes |
|---|---|---|
| **Identity/naming** — `plexTenantIdentifier`, `plexBucketName`, `projectCommonsServices` | no | Pure — extract first, zero risk |
| **SQL builders** — `buildPostgresTenantSql`, `buildDropTenantSql` | no | Pure — extract first |
| **Registry** — `getRegistry`, `saveRegistry`, `registryAdd`, `registryRemove`, `registryUsedRedisIndexes`, `commonsServiceTenants`, `markServicesMigrated` | yes | Self-contained state |
| **Context/targeting** — `plexKubectl`, `plexContextReachable`, `targetsLocalCluster`, `resolvePlexEnvironment` | becomes constructor state | `plexNamespace()` folds into the constant |
| **Credential reads** — `readCommonsS3Credentials`, `readCommonsMeiliKey`, `resolveCommonsS3…` | yes | Cluster-facing |
| **Commons lifecycle** — `ensureCommons`, `ensurePlexServiceRunning`, `wakeJoinedCommonsServices`, `applyCommonsManifest`, `awaitPlexPort`, `enabledCommonsServices`, `getCommonsSpec`, `defaultCommonsSpec`, `normalizeCommonsSpec`, `commonsServiceCatalog` | yes | Cluster-facing |
| **Tenant allocation** — `allocateDatabase`, `allocateRedisDbIndex`, `allocateCommonsRedisIndex`, `allocateStorageBucket`, `releaseCommonsRedisIndex`, `grantPostgresCreateDb`, `registerTenantDatabase`, `registerTenantStorage`, `unregisterTenant` | yes | The dangerous half |
| **Env/config writing** — `commonsEnvValues`, `applyEnvValues`, `joinPlexCommons` | — | **Stays in the trait.** Touches project files and command output; not a clean service yet |

## Phases

**Phase 1 — pure functions + the constant.** `PlexService` with its
`NAMESPACE` constant, plus identity/naming and the SQL builders. Nothing here
touches the cluster. `plexNamespace()` becomes a one-liner over the constant;
the other ten files keep their literals for now. Shim delegates. Suite must stay
green with **zero test edits** — if a test needs editing, the move was not pure.

**Phase 2 — registry + context/targeting.** The first cluster-facing move.
`plexKubectl()`/`plexContextReachable()` read the constructor's `$context`
instead of the trait property. **This is where the readability win either lands
or does not** — today you cannot read any one of the 49 assignment sites and
tell what cluster is being targeted.

**Phase 3 — credential reads + Commons spec.** Cluster-facing reads, low blast
radius, good practice for the threading pattern before the dangerous half.

**Phase 4 — allocation + Commons lifecycle.** Only after 1–3 are proven and the
suite is green.

## Testing

- Every phase: full suite green with **no test edits**. A required test edit
  means behaviour moved, which this plan forbids.
- New unit tests go against `PlexService` directly — `new PlexService('some-context')`,
  no command, no `$this->`. That testability is
  the second real win: today, testing `plexTenantIdentifier()` requires an
  anonymous class composing the trait (see `tests/Feature/ViteConfigMergeTest.php`
  for that pattern).
- `Process::fake()` rules from `cli/CLAUDE.md` apply unchanged. Fake at the
  shell boundary, never by mocking `PlexService` — the kubectl invocation is the
  behaviour worth asserting (it is what caught the `--context` bug that sent a
  "local" join to the production droplet on 2026-08-01).

## Exit gate — decide, do not drift

After **Phase 2**, answer in writing:

1. Did a real bug get **prevented or found** by the explicit dependency?
2. Is `PlexJoinCommand` genuinely easier to read than before?
3. Did the shim stay thin, or did it grow logic of its own?

**If 1 and 2 are not clearly yes, stop and keep the remaining 130 traits.** A
half-converted codebase with both patterns is worse than either pattern alone,
and that risk is the reason this plan is scoped to one trait.

### Exit gate answers (after Phase 2)

Phase 2 moved `kubectl()`, `contextReachable()`, `registry()`, `saveRegistry()`
and the pure registry helpers into `PlexService`, reading the context from the
constructor. The trait keeps seven one-line delegations. Every existing Plex test
passed unedited; new unit tests hit `new PlexService('orbstack')` directly.

1. **Bug prevented or found by the explicit dependency?** No. The move surfaced
   nothing. The day's real finding — eight scaffolders rendering the PHP
   Dockerfile and crashing — came from reading `GeneratesProjectInfrastructure`,
   not from the service boundary.
2. **Is `PlexJoinCommand` easier to read?** Not yet. Its `$this->` calls are
   unchanged, by design (no call-site churn in phases 1–4), so a reader still
   can't see which cluster a call targets from the call site. The service itself
   reads well — targeting now has one visible source, the constructor — but that
   only helps once callers hold a `PlexService` instead of setting `plexContext`.
3. **Did the shim stay thin?** Yes — delegation only, no logic.

**Verdict by this gate's own rule: 1 and 2 are not clearly yes, so stop before
Phase 3.** The one open question worth answering before abandoning the pattern:
converting `PlexJoinCommand`'s call sites to an explicit `$plex = new
PlexService($context)` is the real test of #2. Decide between that single
experiment and stopping here.

### After the gate: Phases 3–4 and the PlexJoinCommand conversion

Decided to continue after the `PlexJoinCommand` experiment showed the half-way
state was worse: with Phase 2 alone the command held a `PlexService` for ten
calls but still had to set `$plexContext` for the nine that did the real work.

- **Phase 3–4 moved into `PlexService`:** the Commons spec and its pure helpers,
  the shared S3 and Meilisearch credential reads, S3 endpoint resolution,
  tenant `.env` values, database SQL execution, the CREATEDB grant, bucket
  creation, tenant registration, Redis index allocation and release, and
  applying the Commons manifest.
- **Stayed on the trait:** everything that prompts, spins, prints or runs another
  command — `ensureCommons()`, the spinner and error output around allocation,
  `resolveCommonsS3Endpoints()`'s warning, `printPlexHint()`, `joinPlexCommons()`,
  `wakeJoinedCommonsServices()` — plus helpers that aren't the Commons at all
  (`.env` editing, PVC release, port polling, `targetsLocalCluster()`).
- **The seam:** `ensureCommons()`, `allocateDatabase()` and `allocateStorageBucket()`
  take an optional `PlexService`. `PlexJoinCommand` passes its own and no longer
  reads or sets `$plexContext`; every other caller is unchanged.
- **One test edit:** `PlexContextWiringTest` now also accepts `new PlexService(`
  as explicit targeting. That is the command conversion, not a phase move.

**Gate question 2, revisited:** yes for `PlexJoinCommand` now — the cluster it
touches is the one `$plex` was built with, visible in `handle()`, with no ambient
property involved. The other 48 commands still set `$plexContext`; converting
them is optional, one command at a time, using the same seam.

## What this does NOT fix

Worth being explicit, because it was the honest finding of 2026-09-09 and still
holds two days later. Of that day's five defects, only two were structural.
Upstream drift (Vite+ shipping its own `server` block, Prisma 6.19 scaffolding
`prisma.config.ts`), a `confirm()` default that aborted non-interactively, and
the `plex`/`managed` data-model split would all have happened exactly the same
way with service classes.

The same is true of 2026-09-10–11: `plex:evict` shipping a `{tenant}` positional
(caught by a test-guard gap, since `CommandShapeTest` covered `mail|sso|vpn` but
not `plex`) and `SharedClusterService::DATA` hardcoding `data-directus` are both
plain literal bugs. No service boundary would have caught either.

This is a legibility investment, not a bug-rate fix. Go in with that
expectation. The `plex ⊆ managed` invariant was closed separately via
`ConfigData::getExternallyHosted()`.
