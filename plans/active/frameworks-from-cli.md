# Frameworks and their creation questions, sourced from the CLI

## Context
Desktop's New project page hardcodes what the CLI should own: the 16 frameworks (`ProjectController::SCAFFOLDERS`), their categories, descriptions, `tech`/`keywords` (`create.tsx` `FRAMEWORK_META`), which ones ask for an email (`WIZARD_FRAMEWORKS`), hidden / coming-soon flags, and Desktop-side defaults (`LaravelOptions::DEFAULTS`). Only Laravel's questions already come from the CLI (`new:options --json`). The other frameworks' `*:new` commands accept no answers as flags (only `--fast`), so Desktop cannot pass choices to them at all. LaraKube Cloud will later feed this data to the CLI, so a second copy in Desktop will drift (same reasoning as the tool-data lock just done).

Intended outcome: the CLI describes each framework and its fields as JSON; Desktop renders them generically and passes the answers back as flags. Adding or changing a framework is a CLI-only change.

## Decision
Scope of the first pass (user's choice): **workshop frameworks first** (Laravel, Statamic, Next.js, Vite, Astro, Docusaurus) fully flag-driven; the other server frameworks (Django, FastAPI, NestJS, AdonisJS, Spring Boot, .NET, Gin, Axum, WordPress) are listed in the schema with no extra fields, so Desktop keeps creating them with `--fast` defaults until they get flags in a later pass.

## The contract: `larakube new:frameworks --json`
One stdout line (existing `EmitsJsonOutput` pattern, like `new:options` / `cloud:providers`):
```
{"success": true, "frameworks": [{
  "slug": "statamic", "label": "Statamic", "description": "...", "category": "cms",
  "tech": "PHP", "logo": "statamic",
  "deployable": true, "hidden": false, "comingSoon": false,
  "command": "statamic:new",
  "fields": [
    {"key": "name",  "type": "text", "label": "App name", "description": "Lowercase letters, numbers and dashes.", "required": true, "arg": "positional", "pattern": "^[a-z][a-z0-9-]*$"},
    {"key": "email", "type": "text", "label": "Your email", "description": "For your site's SSL certificate.", "required": true, "flag": "--email="},
    {"key": "database", "type": "select", "label": "Database", "default": "mysql",
     "options": [{"value": "mysql", "label": "MySQL", "flag": "--mysql", "recommended": true, "unavailableWith": []}]},
    {"key": "features", "type": "multiselect", "options": [...], "conflicts": [["horizon","queues"]]},
    {"key": "search", "type": "select", "nullable": true, "visibleWhen": {"features": "scout"}}
  ]}]}
```
- It is a superset of today's `new:options` question shape (`key, label, multiple, nullable, default, options[{value,label,flag,unavailableWith}], conflicts, requiresFeature`), so Desktop's existing `NewAppQuestion` type and `laravel-options.tsx` renderer generalise instead of being replaced.
- Adds: an explicit `type` (`text | select | multiselect | confirm | password`), `required`, `description`, `recommended`, `hint`, `arg`/`flag` (how the answer is passed), and small conditional rules `visibleWhen`, `forces` (e.g. features include `horizon` → `cache = redis`) and `defaultWhen` (e.g. `ai` → postgres), replacing the rules that live only in code today (horizon forces Redis, `ai` defaults postgres, FrankenPHP rules out SQLite and implies Octane, Next.js fixes cache to Redis).
- Secrets are never fields: Statamic's super-user password stays in `LARAKUBE_STATAMIC_PASSWORD`.
- `new:options --json` stays as is for older Desktop builds (it becomes a thin view of the `laravel` entry).

## CLI work (`cli/`)
1. **Framework metadata on `AppFramework`** (`app/Enums/AppFramework.php`, 511 lines): add `description()`, `category()`, `tech()`, `scaffoldCommand()`, `isHidden()`, `comingSoon()`, `logo()`. WordPress `hidden`; Emdash is not a CLI framework, so it disappears from Desktop.
2. **A question builder** `app/Services/FrameworkQuestions` generalising `NewOptionsCommand`'s builder (it already computes `unavailableWith` via `HasHiddenComponents::isHidden`). Option lists come from the enums (`DatabaseDriver`, `CacheDriver`, `StorageDriver`, `SearchDriver`, `PhpVersion`, `ServerVariation`, `FrontendStack`, `LaravelFeature`) and from `AppFramework::supported*Drivers()`. The framework commands' hand-written inline lists (e.g. `DjangoNewCommand.php:74-136`) are today inconsistent with those enum methods (MariaDB, Django's `database` cache); the enum becomes the single source and the commands read it.
3. **`new:frameworks` command** that emits the contract above (plus a human table without `--json`).
4. **Real flags on the workshop `*:new` commands**, reusing `InteractsWithDynamicOptions::addArchitecturalOptions` (the `--postgres`, `--redis`, `--minio`, `--meilisearch`, `--npm` convention `new` already uses) so a headless run asks nothing:
   - `statamic:new`: add the missing database/cache/storage/search/PHP/feature flags (it already has `--email`, `--content`, `--starter-kit`, `--pro`, `--license`).
   - `nextjs:new`: database, storage, search flags (cache fixed to Redis).
   - `vite:new`, `astro:new`, `docs:new`: a curated `template` select (their upstream `create-*` wizard only runs on a TTY; today `--template=` takes free text). The curated list lives in the CLI.
   - `new` (Laravel): add `--email` is present; make the wizard not re-ask anything the flags already answered (today `--fast` still prompts for frontend, features, search, storage, email), so `--no-interaction` always finishes.
   Extract the four copy-pasted prompts of the eight server frameworks into one trait later (second pass), not now.
5. **Drift tests:** every `AppFramework` that `isScaffoldable()` appears in `new:frameworks`; every option's `flag` and every field's `flag` exists in that command's signature; every `fields[].options[].value` is a real enum case; `new:options` output equals the `laravel` entry's fields.

## Desktop work (`desktop/`)
1. `app/Services/LaraKube/FrameworkCatalog.php` (like `LaravelOptions` / `ToolCatalog`): runs `new:frameworks --json`, caches by CLI path + mtime, deferred prop.
2. `ProjectController`: delete `SCAFFOLDERS`, `WIZARD_FRAMEWORKS`, `DEFAULTS`; `create()` passes the catalog; `scaffold()` validates the submitted answers against the framework's `fields` (the generalised `LaravelOptions::flags()`: honours `visibleWhen`, `forces`, `conflicts`, `unavailableWith`, required) and builds `[command, name, ...flags, '--no-interaction']`. `init()` asks for an email when the framework's schema has an `email` field. Retry still replays the recorded arguments.
3. `create.tsx`: delete `CATEGORIES`, `FRAMEWORK_META`, `isLaravel`/`isStatamic`; build the category pills and search from the catalog (`category`, `tech`, `label`, `description`); render each framework's `fields` with the existing generic renderer (extend `laravel-options.tsx` to text/confirm/select/multiselect). `framework-logo.tsx` stays a renderer, keyed by the CLI's `logo` id (no guessing).
4. **Guard test** (like `NoToolDataInDesktopTest`): fails if `SCAFFOLDERS`, `WIZARD_FRAMEWORKS`, `FRAMEWORK_META` or `CATEGORIES` reappear, or if a framework slug is compared in `create.tsx`.
5. Docs: update Projects page; note the New project form now follows the CLI.

## Reuse (do not rebuild)
`NewOptionsCommand` question builder and `unavailableWith`; `EmitsJsonOutput`/`ReadsCommandOptions`; `InteractsWithDynamicOptions::addArchitecturalOptions` / `buildConfigFromFlags`; `HasHiddenComponents::isHidden`; Desktop `LaravelOptions` (cache, flags, conflicts), `NewAppQuestion` type and `laravel-options.tsx`; the CLI-sourced lock pattern from `NoToolDataInDesktopTest`.

## Risks
- Laravel's own `laravel new` flags (`--pest`, `--teams`, `--workos`) are forwarded by `new` but are not in the schema; out of scope, can be added as a later field group.
- Vite/Astro/Docs templates are upstream's; the curated list needs a decision on which templates to offer.
- The other eight server frameworks keep `--fast` until their pass; the schema marks them `fields: [name]` only, so nothing regresses.
- Older Desktop builds keep working against a newer CLI (`new:options` stays); a newer Desktop against an older CLI falls back to the "CLI too old" message already used for Laravel.

## Verification
- CLI: `pest --parallel` including the new drift tests; `larakube new:frameworks --json` snapshot per framework; run each workshop `*:new` with only flags and `--no-interaction` in a temp folder and confirm it never prompts (Process fakes in tests, then once for real by the user after `./build`).
- Desktop: feature tests with a faked CLI payload covering catalog rendering, answer validation (conditionals, conflicts, required), argument building and the guard test; `composer ci:check`.
- Manual (user, after pushing and the next canary): create Laravel, Statamic, Next.js, Vite, Astro, Docusaurus from Desktop and confirm the form follows the CLI's schema; change a default in the CLI and see Desktop follow without a Desktop change.

## Order
1. CLI: metadata + builder + `new:frameworks` + drift tests (no behaviour change).
2. CLI: flags on the workshop `*:new` commands; wizard no longer re-asks answered questions.
3. Desktop: catalog, generic form, removal of hardcodes, guard test.
4. Docs. Second pass: remaining server frameworks and a shared prompts trait.

Execution note: a copy of this plan goes to `cli/plans/active/frameworks-from-cli.md` (repo plan-location rule) when implementation starts.
