# Eradicate Hardcoded `'main'` Instance Defaults — Corrected Scope, Closed 2026-08-15

**Original plan filed as `plans/active/plan_eliminate_main_instance_defaults.md`.** Its
premise (audited 2026-08-15) turned out to be significantly narrower than
written. This supersedes it rather than executing it as originally scoped —
executing the original instruction literally would have introduced live
regressions, not fixed anything. Details below; see also ADR 0012's
2026-08-15 amendment.

## What the original plan got wrong

It proposed deleting every `$instance === 'main'` recognition check across
`app/Enums/ClusterTool.php`, `InteractsWithToolRegistry.php`, and ~12 Vendor
classes, replacing them with `$instance === null || $instance === ''` only.

That's backwards. `'main'` is **live, persisted data** — every tool deployed
before 2026-08-14 has a `larakube-tools-registry` entry whose `instance`
field is literally the string `"main"`, and (for tools not yet using the
host-derived-slug convention) Kubernetes resources actually named without
any instance suffix at all, which the `'main'` sentinel exists to represent.
Deleting the recognition checks doesn't "clean up legacy naming" — it makes
every one of those checks stop recognizing the tool's own already-deployed
default instance, silently misrouting `{tool}:remove`/`:show`/alias/wiring
commands at a real, live resource.

## What was actually broken (fixed 2026-08-15)

A narrower, real bug: **fallback code that *manufactures* a fresh instance
slug via `instanceSlugFromHost()`** when no registry entry exists to consult.
Since `instanceSlugFromHost()` was intentionally simplified (2026-08-14,
`f59ca28`/`2ff4b87`) to never special-case a tool's own default host anymore
— it always derives a real, non-empty slug now, full stop, which is exactly
right for CRM (built multi-instance-native, no legacy convention to
respect) — any *other* caller that fed its output into removal/show
targeting for a tool with no registry entry would compute a slug that had
never actually been used to name anything, missing the tool's real
(legacy-convention) resources entirely.

Two call sites did this:
- `InteractsWithToolRegistry::resolveInstanceTargetsForDomain()` — both the
  no-domain/`--all` branch and the specific-`--domain=` branch, when the
  registry had no matching entry.
- `AbstractToolRemoveCommand::resolveInstanceTargets()` — the `--all` branch
  when the tool had zero registered instances (the plain no-flags branch a
  few lines below it already correctly fell back to `null`, untouched).

Both now recognize "the operator is asking about this tool's own
conventional default host" (via the same leftmost-label-vs-`hostPrefix()`
check `instanceSlugFromHost()` used to make internally) and return the
literal `'main'` in that case — not a guessed slug, and not a brand-new `''`
sentinel either, since `'main'` is what every existing downstream consumer
(`ConfigData::getSharedServiceHost()`, `AbstractToolShowCommand::resolveHost()`,
`ToolAliasCommand`, `SsoWireCommand`, etc.) already recognizes. Introducing a
second "this means default" spelling would have silently broken all of
those instead of fixing anything — traced and confirmed via `ToolAliasCommand`
specifically, which had a non-tri-state `$instance !== 'main'` check that a
stray `''` would have tripped.

One genuinely pre-existing latent bug was found and fixed along the way,
same family: `DataRemoveCommand::teardown()` computed resource names with a
bare `$instance !== 'main'` check, not accounting for `resolveInstance()`
legitimately returning `null` (the base class's own no-flags/unregistered
fallback) — would have produced a trailing-dash resource name
(`data-secrets-`) for that path. Widened to the standard tri-state check.

## What was deliberately NOT touched, and why

A full sweep found `'main'` used as this sentinel in **37 files**. The
overwhelming majority (Data*, Design*, Notes*, Chat*, Flow*, Task*, Desk*,
Git*, ConfigData, ~30 more) already handle it correctly today — either the
full tri-state check (`=== null || === '' || === 'main'`) or a PHP
truthy-guard (`$instance && $instance !== 'main'`, which already treats `''`
as falsy and short-circuits correctly). Rewriting all of them to eliminate
the literal string `'main'` everywhere — the original plan's actual
end-state — is a much larger, live-cluster-risk-bearing undertaking than
"finish a cleanup pass": it would mean *every* currently-deployed legacy
single-instance tool's Kubernetes resources getting renamed out from under
themselves the next time their `:init` runs, unless that migration is
designed and executed with the same care as ADR 0012's original registry
migration (which was a deliberate, verified, backed-up, one-time hand
transform of live cluster state — not something to do as a side effect of a
code-style pass). That is out of scope here and not recommended as a
"replace all 'main' strings" mechanical exercise; if it's ever wanted, it
needs its own plan scoped around the live-data migration, not the code.

## Verification

- `./php vendor/bin/pest` — full suite green (see repo history for the run
  this fix shipped in).
- `./php vendor/bin/phpstan analyse` — clean.
- Targeted: `ClusterToolLifecycleTest`, `InteractsWithToolRegistryTest`,
  `DataRemoveCommandTest`, `CrmInitCommandTest`/`CrmManifestYamlTest`,
  `ToolRegistryCompassTest`, `MailWireCommandTest`.
