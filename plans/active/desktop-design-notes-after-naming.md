# LaraKube Desktop: notes to pick up after the naming close-out

## Context
The ADR 0021 naming migration is closed (commit `545fd453`) and Desktop work resumes for the workshop. These are the user's eight design questions, each with a recommendation grounded in what the code does today. Nothing here is implemented; the "Next steps" at the end are the candidate work items.

Facts checked: Desktop is NativePHP + Inertia/React and drives the CLI as a child process (`desktop/app/Services/LaraKube/CliRunner.php`). Read data comes from `tool:list --json` / `<tool>:show --json` (`ToolCatalog.php`). It has no settings table of its own; `GlobalSettings.php` reads/writes the CLI's `~/.larakube/config.json`. `spatie/laravel-settings` is not installed.

## 1. Stop naming drift from recurring; what per-instance UI can show
**Prevent it:** the contract is already in code, so Desktop should consume it, never rebuild names.
- `ToolInstance` is the only source of resource names; `ToolNamingDriftTest` fails when `:init` and `:remove` disagree, and `ClusterToolCategoryParityTest` + `CanonicalNamesTest` (new) lock category/tool parity and the no-category rule.
- Rule for Desktop: never compose a name or an `:init` command string. Ask the CLI (`tool:show --json`, `ClusterTool::initCommand()`).
- Add one more guard: a test that every `resources/views/k8s/**` blade for a tool uses the `ToolInstance` preamble (grep for hard-coded `name:` literals), so a new tool can't ship hand-built names. A new tool also needs one `resourceNaming()` arm; make the ledger exhaustive (no `default`), so adding a case without choosing is a compile/test failure.
**Per-instance UI (read from `tool:show --json`):** host + aliases, instance slug, engine, namespace, components (primary/frontend/worker/db) with replica status, identity labels (`larakube.io/tool|component|instance`), Commons DB/bucket/Redis tenant, secrets by kind (credentials/oidc/smtp), VPN middleware, PVCs, and which wirings are active. Needs a small CLI addition if `--json` doesn't already expose components/labels (check `AbstractToolShowCommand.php`).

## 2. Spatie Laravel Settings for Desktop-only preferences?
Recommend **no**. Keep two stores with a clear line: CLI-shared config stays in `~/.larakube/config.json` (already what `GlobalSettings.php` does). Desktop-only UI prefs (theme, window state, last context, collapsed panels) are a handful of keys; use a tiny `settings` table or NativePHP's `Settings` facade, not a package with its own migrations/DTO classes. Revisit Spatie only if prefs grow to typed groups with validation. Never store cluster secrets there (the `Run` model already avoids that).

## 3. VPS vs managed k8s
Don't guess; read the CLI's own signal. `CloudData::isManaged()` (`cli/app/Data/CloudData.php`): `context` set → managed, `ip/user/key` set → VPS (mutually exclusive). Provider comes from `ManagedProvider` (DOKS/EKS/GKE/AKS/CIVO/LKE/CUSTOM) and storage from `defaultStorageClass()` (VPS = k3s `local-path`). UI consequences: VPS shows SSH/Server pages, node-local storage warnings (no expand, no multi-node), `cloud:deploy` SSH-sideload; managed shows provider, storage class, registry push, HA option. Expose it as a `kind: vps|managed` + `provider` field on the context/environment from the CLI rather than re-deriving in PHP.

## 4. Which UI is CLI-driven vs not
- **CLI-driven (all cluster state/actions):** tools list/show (`tool:list --json`), install/remove/wire (`<tool>:init|remove`, `*:wire`), plex/commons, backups, contexts/servers (`cloud:*`), readiness. Every action is a `CliRunner` run recorded as a `Run`.
- **Desktop-native (not CLI):** the `runs` history, `projects` registry (SQLite), CLI installer/updater (`CliInstaller.php`), cloud auth session, preferences, theme/window, external-link opening.
- **Gray:** global settings (CLI file, edited by Desktop), cached catalog (Desktop cache over a CLI read).
Action item: add a short table to the Desktop plan marking each page's data source so the UI can show a "live from cluster / cached at HH:MM" badge.

## 5. Telling users about mail/sso/secrets/vpn/meet `:wire`
Make it data-driven, per instance, not copy text. The vendor contracts already encode capability (`HasSmtpWiring`, `HasOidcWiring`, `HasVpnWiring`, `HasOpenbaoSync`, meet). Expose a `wirings` array in `<tool>:show --json` with `{wire, supported, active, prerequisite_installed}`; Desktop renders chips on the instance card: **active** (done), **available** (button runs `<wire>:wire --tool=… --domain=…`), **needs Zitadel/Stalwart/NetBird first** (link to install it). Unsupported wirings are hidden, not greyed. Order by value: SSO and Mail first.

## 6. Can LaraKube Cloud be the source of k8s templates?
Yes, and it's already planned: `plans/active/remote-template-registry.md`. Cloud serves signed (ed25519) packs of the `resources/views/k8s/*` blades per entitlement; the CLI adds `TemplatePackService` + `templates:sync` and registers a cache dir as the first view path (no call-site changes); rendering stays client-side so secrets never leave the machine. Sequenced v1.1.0 after the entitlements seam in v1.0.0. Not built. Not a workshop item. Embedded blades must remain the offline/airgap fallback.

## 7. User-provided templates
Not possible today (no override mechanism; `cli/config/view.php` has one view path) and no plan covers it. Feasible with the same seam as #6: a user template directory (e.g. `~/.larakube/templates/k8s`) prepended to the view finder, falling back to embedded. Caveats: blades assume variables supplied by the `:init` command and the `ToolInstance` naming contract, so a user template that renames resources breaks `:remove`, backup discovery (label-based) and wiring. Support it as "override a file, keep names/labels", enforced by a render-time check that the output carries the identity labels and `ToolInstance` names.

## 8. Publishing templates for editing
Yes, as the safe form of #7: `larakube templates:publish --tool=<x>` copies that tool's blades into the user template dir (like `vendor:publish`), and the override path then wins. Show a diff against the shipped version on upgrade and warn when the shipped template changed ("your override is N versions behind"). Same naming/label validation as #7. Desktop: a "Customize templates" action on a tool, opening the folder.

## 9. Framework and tool icons: should the CLI bother?
No. Icons are presentation, so Desktop owns the artwork, keyed by the CLI's stable identifiers. The CLI already has a per-tool emoji in `ClusterTool` (terminal output only). Recommendation: Desktop keeps an asset map `tool value -> SVG` (and `framework -> SVG`), with brand logos bundled in the app (no runtime fetch, works offline), and falls back to the CLI emoji, then a generic category glyph, so a new tool never renders blank. The CLI's only job is to emit stable `tool`, `category` and `framework` identifiers in its `--json` output (it already does for tools). A drift test in Desktop should fail when a tool in `tool:list --json` has no icon entry, so adding a tool surfaces the gap. Check trademark/licensing before bundling third-party logos for the workshop; simple-icons covers most.

## 10. Future tools without a new `*:init/*:show/*:remove` set each time
Agree it doesn't scale. Generic verbs already exist (`tool:add`, `tool:show`, `tool:remove`, `tool:list`, `tool:logs`-style bases in `cli/app/Commands/Tool/`), and `AbstractToolShowCommand`/`AbstractToolRemoveCommand` already hold the logic, so the per-tool classes are mostly thin signature wrappers (the retirement just proved that). Direction:
- **Desktop talks only to the generic verbs** (`tool:add --tool=x`, `tool:show --tool=x`, `tool:remove --tool=x`), never `<tool>:init`. A new tool then needs zero Desktop work.
- **A tool = one registry entry:** `ClusterTool` case + vendor class (components, wiring contracts, commons needs) + blade pack. `tool.stub` already generates the class set; extend it so a tool declares its extra flags (e.g. `--with-exporter`, `--engine`) in a vendor-provided options schema that `tool:add` and Desktop render, instead of a hand-written signature.
- **Keep `<tool>:init` as terminal sugar only,** registered from the registry, not hand-maintained classes. Existing plans cover the registry-driven side (`plan_registry_driven_wiring.md`, `tool-show-wiring-plan.md`). Check these before building so we don't fork a second design.
- Constraint to preserve: the "one positional `<environment>`, everything else a `--flag`" rule.
Not a workshop task; capture it as the shape of the next tool added.

## 11. WordPress: tool or framework?
It is currently modeled as a project framework (`app/Enums/AppFramework.php`, `app/Commands/Wordpress/WordpressNewCommand.php`), i.e. "your own code in a repo". Your instinct is right for most WordPress users: they host on managed WordPress SaaS and never touch deployment, so a local-repo scaffold has little pull. The only audience that benefits is developers shipping custom themes/plugins or agencies self-hosting many sites. Recommendation: do **not** feature WordPress as a framework in Desktop for the workshop (hide it from the framework picker). If demand appears, offer it as a **Cluster Tool** (a hosted instance via `wordpress:init`, Commons MySQL/Valkey/S3 media), which fits the "app you host" model better than a repo scaffold. Decide by workshop audience feedback; no code change now beyond hiding it in Desktop.

## 12. Up / Down / Start / Stop on the Projects index
Yes, and the CLI already has every verb, so this is a thin UI (all run through `CliRunner` and logged as `Run`s):
- `up <env>` deploy/orchestrate, `start <env>` scale back up, `stop <env>` scale to zero (data kept), `down <env>` remove resources; `down --vols` wipes volumes, `--full` total cleanup, `--k8s` wipes generated manifests, `--dry-run` previews, `--force` skips the prompt.
- **State-driven buttons.** Detect state per project+environment, then show only valid actions: *not deployed* → **Up**; *running* → **Stop**, **Down**, **Down + purge**; *stopped (0 replicas)* → **Start**, **Down**, **Down + purge**; *partial/failing* → **Heal** (`heal` exists) plus Logs.
- **Safety.** "Down + purge" (`--vols`/`--full`) is irreversible: require typing the project name, and always offer **Preview** (`--dry-run`) first. This matches the no-destructive-steps rule: Desktop never runs a purge without an explicit, named confirmation.
- **Gap to close.** There is no single CLI "status" command that returns running/stopped/absent per environment. Add a read-only `status <env> --json` (replica counts per workload, from labels) so Desktop doesn't scrape `kubectl`. Until then Desktop can derive state from `tool:list`-style reads, but that's a stopgap.
- Per-environment: local vs cloud environments get separate buttons; cloud Down/purge gets the strongest confirmation.

## Next steps (when we return to Desktop)
1. Check/extend `tool:show --json` with components, labels, secrets and a `wirings` array (#1, #5); Desktop reads only that.
2. Add `kind`/`provider` to the context payload (#3).
3. Add the blade-uses-ToolInstance guard test and make `resourceNaming()` exhaustive (#1).
4. Defer #6-#8 until after the workshop; fold #7/#8 into the remote-template-registry plan as one overlay seam.

## Verification
Docs-only; no code changes in this plan. When implemented: `./vendor/bin/pest` (new guard tests), `larakube tool:show <env> --json` shape check, and Desktop pages against a real context.
