# Commons Redis Index Audit — Handoff for OpenCode

## Context

While auditing `DataTool`'s S3 bucket sharing (see `plans/completed/clustertool-vendor-decomposition.md` and `plans/completed/` for that fix), a real bug was found and fixed in `DesignTool`/Penpot: `PENPOT_REDIS_URI` was hardcoded to the Commons Valkey's logical DB index `0` directly in the Blade template, completely bypassing `allocateCommonsRedisIndex()` — the mechanism that reserves a dedicated index per tenant in the Plex tenant registry so `FLUSHDB`/teardown never touches another tool's keys. That fix is DONE (see `app/Enums/DesignTool.php`, `app/Commands/Design/DesignInitCommand.php`, `resources/views/k8s/design/shared.blade.php`, `tests/Feature/DesignInitCommandTest.php`).

A full sweep of every Blade template referencing the Commons Redis (`redis.{{ $plexNamespace }}.svc.cluster.local`) turned up **the same bug class in one more category, plus a different-but-related bug in three more.** This file is the handoff for fixing those. It's self-contained — no other conversation context required.

## How the mechanism is supposed to work (reference)

- `app/Traits/InteractsWithPlex.php::allocateCommonsRedisIndex(string $tenant): ?int` — idempotently allocates one of 16 logical DB indices (0-15) to a tenant string, persists it to the Plex tenant registry (`plex-registry` ConfigMap), returns the same index on re-runs. Returns `null` when all 16 are taken (caller must handle this — see the Notes/Design pattern below).
- `app/Traits/InteractsWithPlex.php::releaseCommonsRedisIndex(string $tenant): void` — frees it.
- `app/Commands/Tool/AbstractToolRemoveCommand.php::dropCommonsTenants()` (only runs on `{tool}:remove --purge`) automatically calls `releaseCommonsRedisIndex()` for every key in `$tool->commonsRedisKeys()` — i.e. `ClusterTool::commonsRedisKeys()`, which dispatches to the vendor's `HasCommonsRedisKeys::commonsRedisKeys()` implementation (`app/Contracts/HasCommonsRedisKeys.php`). **This is the ONLY place release happens — a vendor that doesn't implement `HasCommonsRedisKeys` never gets its index released, ever, even on `--purge`.**
- The correct init-side pattern (see `app/Commands/Notes/NotesInitCommand.php:147-152` or the just-fixed `app/Commands/Design/DesignInitCommand.php`):
  ```php
  $redisIndex = $this->allocateCommonsRedisIndex($tenantKey);
  if ($redisIndex === null) {
      $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');
      return 1;
  }
  // ... pass 'redisIndex' => $redisIndex into the view() call
  ```
- The Blade side: the connection string must read `redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}` — never a literal digit.

## Findings — every category referencing the Commons Redis

| Category | Vendor file | Allocates via `allocateCommonsRedisIndex()`? | Blade uses `{{ $redisIndex }}`? | Implements `HasCommonsRedisKeys`? | Bug class |
|---|---|---|---|---|---|
| GIT (Forgejo) | `GitForgeTool` | ✅ `'forgejo'` (`GitInitCommand.php:151`) | ✅ | ✅ `['forgejo']` | none — correct |
| NOTES (Outline) | `NoteTool` | ✅ `$dbName` = `'outline'` (`NotesInitCommand.php:147`) | ✅ | ✅ `['outline']` | none — correct |
| SHEETS (Teable) | `SheetTool` | ✅ (`SheetsInitCommand.php`) | ✅ | ✅ `['teable']` | none — correct |
| DESIGN (Penpot) | `DesignTool` | ✅ (just fixed) | ✅ (just fixed) | ✅ `['penpot']` (just fixed) | **FIXED this session** |
| **CRM (Twenty)** | `CrmTool` | ✅ `'crm_twenty'` (`CrmInitCommand.php:76`) | ✅ | ❌ | **Class B — leak** |
| **SUPPORT (Chatwoot)** | `SupportTool` | ✅ `'support_chatwoot'` (`SupportInitCommand.php:77`) | ✅ | ❌ | **Class B — leak** |
| **LINK (Kutt)** | `LinkTool` | ✅ `'link_kutt'` (`LinkInitCommand.php:77`) | ✅ (as `REDIS_DB`, not a URI — `resources/views/k8s/link/shared.blade.php:57-58`) | ❌ | **Class B — leak** |
| **DATA (Directus)** | `DataTool` | ✅ `$dbName` (`DataInitCommand.php:121`, directus branch only) | ✅ (`resources/views/k8s/data/directus.blade.php:69`) | ❌ | **Class B — leak** |
| **ERRORS (GlitchTip)** | `ErrorTool` | ❌ — no call anywhere in `ErrorsInitCommand.php` | ❌ — hardcoded `/15` (`resources/views/k8s/errors/shared.blade.php:14`, the Plex-Commons `@else` branch only — the `@if ($noPlex)` branch at line 11 correctly uses GlitchTip's own dedicated `glitchtip-cache` Redis at its own index 0, which is NOT a bug, don't touch it) | ❌ | **Class A — collision risk (same as the Penpot bug)** |

**Class A** (ERRORS only): identical shape to the Penpot bug just fixed — unregistered, hardcoded index, real collision + cross-tenant-FLUSHDB risk.

**Class B** (CRM, SUPPORT, LINK, DATA/Directus): allocation already works correctly today (each was independently wired to call `allocateCommonsRedisIndex()` with the right tenant key) — the ONLY gap is that `{tool}:remove --purge` never releases the index, because the vendor class doesn't declare it via `HasCommonsRedisKeys`. This is a slow leak, not an immediate collision: each purge-and-reinstall cycle on one of these 4 tools permanently burns one of the 16 available indices. Given the Commons only has 16 slots total and 9 categories can already claim one each, this is worth closing before it's actually hit in practice, but it is not urgent in the way Class A is.

**Confirmed NOT a bug** — `resources/views/k8s/plex/commons.blade.php` also references `redis`/`Redis`, but that's the Commons Redis/Valkey Deployment's OWN manifest (the infrastructure itself), not a tenant consuming it. No action there.

## The fix

### Class B (CRM, SUPPORT, LINK, DATA) — trivial, one line each

No Blade or InitCommand changes needed — allocation already works. Just add the interface + matching tenant key to each vendor class (mirror `app/Enums/DesignTool.php`'s addition exactly):

```php
// app/Vendors/CrmTool.php — add `HasCommonsRedisKeys` to the implements list, add:
public function commonsRedisKeys(): array
{
    return ['crm_twenty']; // must match the exact string CrmInitCommand.php:76 allocates with
}
```
```php
// app/Vendors/SupportTool.php:
public function commonsRedisKeys(): array
{
    return ['support_chatwoot']; // must match SupportInitCommand.php:77
}
```
```php
// app/Vendors/LinkTool.php:
public function commonsRedisKeys(): array
{
    return ['link_kutt']; // must match LinkInitCommand.php:77
}
```
```php
// app/Enums/DataTool.php — this one is trickier: DataTool has TWO cases (POCKETBASE,
// DIRECTUS), but only Directus ever allocates a Redis index (PocketBase uses embedded
// SQLite, no Commons anything — see the sibling S3-bucket fix already done in this
// file for the same POCKETBASE/DIRECTUS split). Match that pattern:
public function commonsRedisKeys(): array
{
    return match ($this) {
        self::POCKETBASE => [],
        self::DIRECTUS => ['data_directus'], // must match the $dbName DataInitCommand.php:121 allocates with — verify the exact string before writing this, it may include an instance suffix already applied elsewhere
    };
}
```
**Verify the exact allocate-side tenant string for each before writing the return value** — grep the InitCommand and confirm it matches character-for-character, the same way `GitForgeTool`/`NoteTool`/`SheetTool`/`DesignTool` already do. A mismatch here means the release call would target a tenant key that was never actually allocated (silent no-op), which defeats the whole fix.

Add the `use App\Contracts\HasCommonsRedisKeys;` import to each file.

### Class A (ERRORS/GlitchTip) — full fix, same shape as the Penpot fix just shipped

1. `app/Vendors/ErrorTool.php`: add `HasCommonsRedisKeys`, return `['glitchtip']` (matching the Postgres tenant name already used at `ErrorsInitCommand.php:71`).
2. `app/Commands/Errors/ErrorsInitCommand.php`: right after the `allocateDatabase(DatabaseDriver::POSTGRESQL, 'glitchtip', $dbPassword)` call (~line 71), add:
   ```php
   $redisIndex = $this->allocateCommonsRedisIndex('glitchtip');
   if ($redisIndex === null) {
       $this->laraKubeError('The Commons Valkey has no free logical DB index (all 16 in use).');
       return 1;
   }
   ```
   Then add `'redisIndex' => $redisIndex,` to the `view('k8s.errors.shared', [...])` call (~line 100). **Only compute this when NOT `--no-plex`** — check how the `$noPlex` bool is already threaded through this command (the Blade file's `@if ($noPlex)` branch doesn't need `$redisIndex` at all, so either compute it unconditionally and let it be unused in that branch, or gate it — follow whatever the existing `$dbPassword`/`allocateDatabase` call already does for the `$noPlex` case as the template, since GlitchTip already has this exact conditional-allocation shape for Postgres).
3. `resources/views/k8s/errors/shared.blade.php:14` — change:
   ```
   redis-url: {{ base64_encode("redis://redis.{$plexNamespace}.svc.cluster.local:6379/15") }}
   ```
   to:
   ```
   redis-url: {{ base64_encode("redis://redis.{$plexNamespace}.svc.cluster.local:6379/{$redisIndex}") }}
   ```
   **Do not touch line 11** (the `@if ($noPlex)` branch's `glitchtip-cache:6379/0`) — that's GlitchTip's own dedicated Redis, correct as-is.
4. Add a regression test to `tests/Feature/ErrorsInitCommandTest.php` (or wherever it lives) mirroring the one just added to `tests/Feature/DesignInitCommandTest.php` — fake the `plex-registry` ConfigMap with index 0-14 (or just index 15, whichever the real allocator would hand out first) pre-claimed by a different tenant, assert the rendered manifest does NOT contain the old hardcoded value and DOES contain a dynamically-resolved one.

## Confirmed non-issues — don't waste time re-checking these

- **`mail:wire` / `sso:wire`**: only patch SMTP/OIDC secrets on existing Deployments. Neither reads nor writes Redis config for any tool. No blast radius.
- **`plex:show` / `plex:rotate`**: their tenant reverse-lookup goes through `ClusterTool::forCommonsResource()`, which checks `commonsDatabases()` and `commonsBuckets()` ONLY — `commonsRedisKeys()` is not part of that lookup path at all (it's used exclusively by the allocate/release functions and the generic `{tool}:remove --purge` teardown). Adding `HasCommonsRedisKeys` to a vendor does not change anything `plex:show`/`plex:rotate` display.
- **`{tool}:remove` without `--purge`**: never calls `dropCommonsTenants()` at all (see `AbstractToolRemoveCommand::handle()` — gated behind `if ($isPurging)`), so it never touches the Redis index either way, for any tool, today or after this fix. Only `--purge` is affected.

## Live-cluster note

Per this repo's hard rule (`feedback_no_manual_kubectl.md` in memory / CLAUDE.md): never patch the live cluster directly. After landing the code fix, the affected tools need `{tool}:init` re-run to pick up the change (idempotent — safe to re-run). For Class A (ERRORS), same caveat as the Penpot fix: if GlitchTip is live and index 15 happens to already be legitimately claimed by something else in the registry by the time this lands, GlitchTip's cache will move to a new index on re-init — active session/cache state at the old index is not primary data (GlitchTip's source of truth is Postgres, same reasoning as Penpot), so this is low-risk, but worth a heads-up before re-running on `design.luchtech.dev`'s cluster. For Class B (CRM/SUPPORT/LINK/DATA), there is no init-time behavior change at all — only future `--purge` removals start correctly releasing the index. Nothing needs re-running for those four.

## Separate, unrelated finding — flag but do not bundle into this fix

`app/Enums/SharedClusterService.php` — `presenceProbe()` (~line 239) and `getLabel()` (~line 192) for `self::DATA` are hardcoded to `'deployment data-directus -n larakube-shared'` / `'Directus'`, ignoring PocketBase entirely. If PocketBase is the actually-installed DATA engine, this system reports DATA as "not installed" / mislabels it. `FLOW`'s equivalent does this correctly today via a label selector: `'deployment -l "app in (flow-n8n, flow-windmill)" -n larakube-shared'` (~line 222). The fix is almost certainly to give DATA the same multi-engine label-selector treatment, but this is a **separate file/system** (`SharedClusterService`, the "always-on `up`-reconciled shared services" enum) from `ClusterTool`/`DataTool`, unrelated to the Redis work above — don't mix them into one commit. Confirm with the user before starting; this was surfaced but not yet explicitly approved for a fix.

## Verification checklist (mirror what was done for the Design/Penpot fix)

Exact existing test files (verified — use these, not guesses): `tests/Feature/SupportInitCommandTest.php`, `tests/Feature/LinkInitCommandTest.php`, `tests/Feature/ErrorsInitCommandTest.php`, `tests/Feature/DataInitCommandTest.php`. **`Crm` has no test file at all today** (`find tests -iname "*Crm*"` returns nothing) — the `CrmTool` fix can't be regression-tested against an existing file; either add a new `tests/Feature/CrmInitCommandTest.php` (there may be a reason none exists — check `app/Commands/Crm/` is even wired/registered before assuming this is an oversight) or at minimum confirm the full suite still passes with the change.

```bash
./php -l <every changed file>
./php vendor/bin/pest tests/Feature/SupportInitCommandTest.php tests/Feature/LinkInitCommandTest.php tests/Feature/DataInitCommandTest.php tests/Feature/ErrorsInitCommandTest.php
./php vendor/bin/pest   # full suite, run WITHOUT --parallel for a clean read — this repo has a known pre-existing parallel-test-worker race in ServerManifestTest/ServicesManifestTest/FrontendManifestTest (shared temp dir), unrelated to this work; if only those fail under --parallel, re-run serially to confirm
./php vendor/bin/phpstan analyse app/   # must end "[OK] No errors"
git status --porcelain   # review the diff before considering this done — only intended files should show as modified
```

Never commit unless the user explicitly asks (their hard rule). Leave everything in the working tree for review.
