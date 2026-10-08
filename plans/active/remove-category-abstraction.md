# Remove the Category Abstraction from ClusterTool

## Problem

`ClusterTool` has 29 "category" cases (`FLOW`, `DATA`, `ANALYTICS`, `CHAT`, `CRM`, `GIT`, `SHEETS`, `SIGN`, `TASKS`, `LINK`, `SUPPORT`, `DRIVE`, `ERRORS`, `INSIGHTS`, `MAIL`, `MONITOR`, `NOTES`, `PASSWORDS`, `RECORD`, `SECRETS`, `SSO`, `UPTIME`, `VPN`, `WEBMAIL`, `DASHBOARD`, `MEET`, `DESIGN`, `PASTE`, `DNS`) marked `isLegacy(): true` and excluded from `shippedCases()` — meaning **no user, command, or prompt can ever select one directly**. They exist purely as an internal indirection layer: a real tool (`N8N`) "belongs to" a category (`FLOW`), and `canonicalTool($engine)` translates category → real tool everywhere that needs an actual identity (namespace, labels, registry, output text).

This is backwards from how the rest of this codebase already models tools. The `ClusterToolVendor` composition pattern (`app/Contracts/ClusterToolVendor.php`, `app/Tools/*.php`) is explicit that capabilities are **optional attributes a vendor implements**, checked via `instanceof` — never a shared parent identity. Category does the opposite: it's an exclusive, single-parent hierarchy (`canonicalTool()` assumes exactly one category owns each real tool), bolted on *beside* the attribute system rather than expressed through it. Most tools' actual shared behavior doesn't fit a strict one-parent tree at all — code reuse across engines is a composable *capability* (which shared deploy class handles me), not a taxonomy a tool belongs to.

**Confirmed live impact, not just a style complaint.** `ToolInstance::labels()` sets `'larakube.io/tool' => $this->tool->value` — literally whatever `ClusterTool` case was passed to `ToolInstance::forHost()`/`forInstance()`. Anywhere a category case reaches that constructor, the deployed Kubernetes resources carry the **wrong** tool identity in their labels. Already found and fixed for Flow (`fix(n8n): remove legacy "flow" category leakage`, commit `a916420e`) — verified live on `larakube-159.89.205.239`'s actual n8n deployment, which still carries `larakube.io/tool: flow` today pending its next redeploy. The same bug is confirmed **still live** in at minimum:

- `App\Commands\Data\DataInitCommand` — `ToolInstance::forInstance(ClusterTool::DATA, $instance, $engine)` (3 call sites) — affects every PocketBase/WordPress/Directus deployment.
- `resources/views/k8s/chat/{ingress,admin,mas,matrix}.blade.php` — all four construct `ToolInstance::forInstance(ClusterTool::CHAT, ...)` — affects every Matrix deployment.
- `resources/views/k8s/analytics/{ingress,shared}.blade.php` — `ToolInstance::forHost(ClusterTool::ANALYTICS, ...)` — affects every Plausible/Umami deployment.

A broader grep for `ClusterTool::<CATEGORY>` across `app/` and `resources/views/k8s/**/*.blade.php` returns 207 matches across the 29 categories — the full audit (Phase 0 below) is what turns that into a confirmed per-family list, but the pattern is clearly systemic, not a one-off in Flow.

## Why this happened

The CLI-surface migration (naming convention ADR, "category gone," 2026-10-02 per project history) fixed what `--tool=` accepts and what gets registered as an Artisan command. It never reached the layer underneath: the `ClusterTool` enum itself, `ToolInstance` construction, and the Blade templates still organize identity around the category case internally, with `canonicalTool()`/`isLegacy()` papering over it at the edges. Anywhere a call site forgot to translate category → canonical before constructing identity, the bug is silent — it only surfaces as a wrong label on a live resource, which nothing currently checks for.

## Target architecture

**No `ClusterTool` case may be "legacy."** Every case is a real, directly-selectable tool. Shared deploy/show/remove logic across engines (the only thing category was ever legitimately useful for) becomes a vendor-declared attribute, not a parent enum case:

```php
interface HasSharedInitFamily
{
    /** The shared {Family}InitCommand (and its Show/Remove counterparts) this engine deploys through. */
    public function initFamily(): string; // ::class of the shared command base
}
```

Implemented only by vendor classes that genuinely share deploy logic with another engine — `N8n`/`Windmill` (→ the class currently named `FlowInitCommand`), `PocketBase`/`WordPress`/`Directus` (→ `DataInitCommand`), `Plausible`/`Umami` (→ `AnalyticsInitCommand`). Every other vendor simply has no shared family — its tool-named Init/Show/Remove command *is* the whole implementation, no shared base class needed at all.

`ToolInitCommands::family()` stops matching on a fake parent case and matches directly on the real tool cases that share one:

```php
public static function family(ClusterTool $tool): string
{
    return match ($tool) {
        self::N8N, self::WINDMILL => FlowInitCommand::class, // or its renamed successor
        self::POCKETBASE, self::WORDPRESS, self::DIRECTUS => DataInitCommand::class,
        self::PLAUSIBLE, self::UMAMI => AnalyticsInitCommand::class,
        default => throw new LogicException(...), // no family — the tool's own {Tool}InitCommand is used directly
    };
}
```

`canonicalTool()` and `isLegacy()` are deleted entirely once no case needs either — there is nothing left to redirect from or exclude.

**The 25 single-target categories collapse completely**, not just lose their enum case. `CrmInitCommand`/`TasksInitCommand`/`SignInitCommand`/etc. are deleted outright; their logic moves one level up into `TwentyInitCommand`/`PlankaInitCommand`/`DocumensoInitCommand`/etc. directly (`extends AbstractToolInitCommand` instead of `extends CrmInitCommand`). These were never actually providing code reuse — there was only ever one real tool on the other end.

For the 3 genuine multi-engine families, the shared class itself should probably be renamed away from category vocabulary too (e.g. `FlowInitCommand` → something that names the *capability* instead of a product category — exact naming is an open question below), but keeping the class is correct: 2-3 engines genuinely sharing deploy logic is real reuse, not a fake taxonomy.

## Audit (Phase 0 — do this before touching any code)

For each of the 29 categories, confirm:
1. Every file referencing `ClusterTool::<CATEGORY>` (commands, traits, Blade templates) and whether it's constructing identity (`ToolInstance::forHost/forInstance`, `registerDeployedTool`, labels, output text — **wrong**) vs. purely internal dispatch (`ToolInitCommands::family()` itself — fine to keep until Phase 1/2 replaces it).
2. For the 25 single-target ones: confirm `canonicalTool()` really does resolve 1:1 with no `$engine` branching (already implied by `canonicalTool()`'s match, but confirm no command passes a non-null `$engine` expecting different behavior).
3. Whether the live cluster-visible bug (wrong `larakube.io/tool` label) is actually present, same check as Flow/Data/Chat/Analytics above — this determines whether that family's live resources will need the same "safe, self-healing on next redeploy" note Flow got.

Output: a table, one row per category, columns = {single-target or multi-engine, files referencing the category case, label bug confirmed Y/N, target collapse class name}.

## Phasing

**Phase 1 — the 3 multi-engine families (DATA, FLOW, ANALYTICS).** Introduce `HasSharedInitFamily`, rewrite `ToolInitCommands::family()`/`::for()` to key off real tool cases, fix every confirmed-broken call site (`DataInitCommand`, the 4 chat templates, the 2 analytics templates, plus whatever Phase 0 finds for these three specifically), delete the `DATA`/`FLOW`/`ANALYTICS` enum cases. This is the hard 10% and proves the pattern before the mechanical sweep. `CHAT` has no `$engine` branch in `canonicalTool()` (always → `MATRIX`) despite having the same bug — fold it into Phase 1 or Phase 2 by that evidence, not by assumption; Phase 0 settles it.

**Phase 2 — the remaining single-target categories.** Mechanical collapse, batchable in small independent groups (e.g. 4-5 families per PR) since each is isolated and low-risk. Delete the shared base class, fold its logic into the one real tool's command, delete the enum case, fix any Blade templates that constructed identity from the category case.

**Phase 3 — cleanup.** Delete `canonicalTool()` and `isLegacy()` from `ClusterTool` entirely. Remove the now-dead `shippedCases()` legacy filter (becomes `self::cases()` again, or close to it — check for other uses of `isLegacy()` first).

Every phase: full Pint/PHPStan/Pest, and for any family where Phase 0 confirms the live-label bug, the same note Flow got — self-healing on next redeploy/touch, no orphaned or duplicate resources, because `spec.selector.matchLabels` only ever used `app: {{ $deployment }}`, never the `larakube.io/*` labels. Confirm that invariant holds per-family in Phase 0 too; don't assume it's universal before checking.

## Open questions before implementation

1. **Naming for the 3 surviving shared classes.** `FlowInitCommand`/`DataInitCommand`/`AnalyticsInitCommand` need names that describe the shared *capability*, not a product category (e.g. something naming "multi-engine app with pluggable storage," not "Flow"). Needs your call, not a guess.
2. **PR granularity for Phase 2** — one PR for all 25, or batched? Batching is safer to review but is 5-6+ separate PRs.
3. **Does this block the signed DMG release**, or land after? Given this is `cli/` only (no Desktop changes), it doesn't block the Desktop release train directly — but worth confirming since Desktop embeds a CLI binary version.
