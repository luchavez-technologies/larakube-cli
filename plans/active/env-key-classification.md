# Env key classification

**Priority: HIGH.** Read this before any task that touches env keys, `dotenv:push`/`dotenv:pull`, the CI build env, secrets delivery, the `.env.<env>` seed, Desktop's settings table, or adding a framework. Design: [ADR 0042](../../docs/decisions/0042-one-classifier-for-environment-keys.md). Not started.

## Why
Five places decide whether a key is secret, a setting, or needed at build time, each with its own rules. When they disagree, values go to the wrong place: a service account key in a ConfigMap, runtime settings baked into an image, Desktop's "Local Only" on values nothing pushes. Each new framework would have to be taught to all five. The fix is one classifier that everything asks.

## Until this is built
- Don't add another rule list. Change the existing ones together: `ReadsEnvSources::isSecretKey()`/`hasEmbeddedCredentials()` and Desktop's `inferIsSecretKey()` must stay identical.
- Build-time prefixes belong in `AppFramework::publicEnvPrefixes()`, nowhere else.

## Today's rule sites (all move onto the classifier)
| Where | What it decides |
|---|---|
| `app/Traits/ReadsEnvSources.php` `isSecretKey()`, `hasEmbeddedCredentials()` | secret by name or value |
| `desktop/resources/js/components/environment-variables-table.tsx` `inferIsSecretKey()` | Desktop's own copy |
| `app/Traits/ConfiguresCloudEnvironment.php` `buildPublicEnvScript()`, `buildStaticPublicEnvScript()` | build-time keys in the workflow (hardcodes `VITE_`) |
| `app/Traits/PushesBuildEnvToCi.php` `buildEnvPairs()` | `<ENV>_BUILD_ENV` keys |
| `app/Data/ConfigData.php` `getPlexManagedKeys()`, `getAllPublicEnvironmentVariables()`, `getAllSecretEnvironmentVariables()` | managed keys |
| `app/Commands/DotenvPushCommand.php` | secret/setting split, Plex skip |
| `app/Traits/GeneratesProjectInfrastructure.php` `seedEnvironmentEnvFile()` | what a new `.env.<env>` gets |

## Phases

### 1. The classifier (CLI only; Desktop untouched)
Goal: every "what kind of key is this" decision in the CLI goes through one class. Behaviour stays the same, except for the three fixes listed under "Intended changes". Work on branch `refactor/env-key-classifier` off `develop`.

**New code**
- `app/Support/EnvKeyClass.php`: a `final readonly` value object with
  - `string $key`;
  - `bool $managed` (true = LaraKube derives the value; never pushed from a local file over LaraKube's own);
  - `bool $secret`;
  - `bool $build`;
  - `string $reason`: a short, stable slug naming the rule that decided, e.g. `component-secret`, `plex-managed`, `framework-prefix:VITE_`, `name:TOKEN`, `value:pem`, `value:url-credentials`, `value:service-account`, `reference-of:VITE_APP_NAME`, `default`.
- `app/Support/EnvKeyClassifier.php`, constructed per call as `new EnvKeyClassifier($config, $environment)` (no container binding needed). Public methods:
  - `classify(string $key, ?string $value = null): EnvKeyClass`;
  - `classifyAll(array $pairs): array<string, EnvKeyClass>`. It also marks keys referenced as `${KEY}` inside build-time values as `build`, with reason `reference-of:<KEY>`, unless they are secret.
- **Rule order: first match wins on each axis.**
  - **secret:** component secret keys (`getAllSecretEnvironmentVariables`), then the name patterns, then the value checks.
  - **managed:** `getPlexManagedKeys($env)` and the keys of `getAllPublicEnvironmentVariables` / `getAllSecretEnvironmentVariables`. Record managed now, but don't act on it beyond the Plex exclusion that exists today (Desktop uses it in phase 3).
  - **build:** for a static SPA (`AppFramework::isStaticSpa()`), every non-secret key. Otherwise, keys matching `AppFramework::publicEnvPrefixes()`, plus references from `classifyAll()`. A secret is never `build`.
- Phase 2's overrides (`envKeys`) slot in first, at the top of each axis; leave a clearly named private method returning `[]` for now.
- **Name patterns, in one constant:** `PASS`, `SECRET`, `KEY`, `TOKEN`, `PRIVATE`, `CREDENTIAL`, `DSN`, substring and case-insensitive. This is today's CLI list, which matches Desktop's `inferIsSecretKey()`.
- **Value checks:** move today's `hasEmbeddedCredentials()` into the classifier: a URL with `user:pass@`, `-----BEGIN`, or `"private_key"`.

**Call sites to move (the whole list; grep to confirm nothing else decides this)**
| File | Today | After |
|---|---|---|
| `app/Traits/ReadsEnvSources.php` | `isSecretKey()`, `hasEmbeddedCredentials()` hold the rules | thin wrappers calling the classifier, marked `@deprecated`; the rules live only in the classifier |
| `app/Commands/DotenvPushCommand.php` ~L157–175 | secret/setting split and Plex exclusion, inline | `classifyAll()`; `secret` goes to OpenBao/Secret, the rest to the ConfigMap, `managed && plex` is excluded |
| `app/Commands/DotenvCommand.php` ~L101–122, ~L190 | name-only `isSecretKey()` | `classify($key, $value)`, so value checks apply here too |
| `app/Traits/ConfiguresCloudEnvironment.php` `buildPublicEnvScript()`, `buildStaticPublicEnvScript()` | hardcoded `VITE_` and its own reference scan | the `build` flag from `classifyAll()` |
| `app/Traits/PushesBuildEnvToCi.php` `buildEnvPairs()` | its own prefix loop | the `build` flag |
| `app/Traits/GeneratesProjectInfrastructure.php` ~L546 `seedEnvironmentEnvFile()` | `getPlexManagedKeys` directly | the `managed` flag (same result) |
| `app/Commands/Data/DataWireCommand.php` L78, `DataUnwireCommand.php` L109 | their own fallback list `['VITE_','PUBLIC_','NEXT_PUBLIC_','ASTRO_']` | the classifier; drop the fallback list (`ASTRO_` is not a real prefix) |

**Intended changes (each needs its own test)**
1. `dotenv <env>` (Desktop's table) treats a value with credentials or a PEM block as secret, the same as `dotenv:push`.
2. `buildPublicEnvScript()` uses the framework's prefixes, not `VITE_` (affects Astro and Next.js server apps).
3. `DataWireCommand` and `DataUnwireCommand` lose the stray `ASTRO_` fallback.

**Tests**
- New `tests/Unit/EnvKeyClassifierTest.php`, at least one test per rule and per `reason` slug, including:
  - `DATABASE_URL=postgres://u:p@h/db` is secret (`value:url-credentials`);
  - `LARAKUBE_GOOGLE_PLATFORM_CREDENTIALS` is secret by name;
  - a key named `SIGNER` holding `{"private_key":…}` is secret by value;
  - `VITE_APP_NAME="${APP_NAME}"` makes `APP_NAME` build (`reference-of:VITE_APP_NAME`);
  - `VITE_X` referencing `${DB_PASSWORD}` does not make `DB_PASSWORD` build;
  - a Plex-managed key is managed;
  - for every `AppFramework` case, its prefixes come out build and a `*_SECRET` key never does (the matrix test);
  - a static SPA puts every non-secret key in build.
- These existing tests must pass **without edits to their assertions**:
  - `tests/Feature/DotenvPushCommandTest.php`;
  - `tests/Feature/DotenvCommandTest.php`;
  - `tests/Feature/GhaWorkflowTest.php`;
  - `tests/Feature/EnvironmentPlexIntentTest.php`;
  - `tests/Unit/AppFrameworkStaticSiteTest.php`;
  - the GitLab and CloudConfigure feature tests.
- Add tests only for the three intended changes.
- Follow ADR 0019 (`docs/decisions/0019-test-fidelity-conventions.md`): `Process::fake` / `Saloon::fake` for everything, helper names unique across the suite, `TemporaryDirectory` for temp dirs.

**Done means**
- `composer test` (the full suite) passes with zero failures.
- `composer analyse` passes, run separately and never at the same time as the suite.
- `vendor/bin/pint` is clean.
- `grep -rn "PASSWORD\|'TOKEN'\|publicEnvPrefixes\|getPlexManagedKeys" app` shows them only in the classifier, `AppFramework`, `ConfigData` and the deprecated wrappers.
- Commits on the branch: `refactor(env): …` for the move and `fix(env): …` for each intended change, each with a body explaining why.

**Verified afterwards by Claude Code, not the implementer**
- Review the diff and rerun the full suite.
- Then the user runs `./build` and Push for staging from Desktop. The ConfigMap and Secret key sets on `hello-cloud` must match the ones before (57 settings, 10 secrets).

### 2. Overrides
- `.larakube.json` gets `envKeys: {KEY: {secret?: bool, build?: bool}}` on `ConfigData`, project-wide.
- `larakube env:mark <KEY> --secret|--setting|--build|--runtime|--reset`. It uses `RequiresFlagsWhenNonInteractive`, is idempotent, and never asks for a hand edit.
- A key that is changed from secret to setting is removed from OpenBao on the next push (the cleanup `DotenvPushCommand::removeSettingsFromOpenBao()` already does this).

### 3. Desktop shows the CLI's answer
- `larakube dotenv <env> --json` adds `owner`, `secret`, `build` and `reason` to each row, plus `--classify=KEY` for a key typed into a new row.
- Desktop deletes `inferIsSecretKey()`.
  - Managed rows are read-only, with a "Managed by LaraKube" badge and the reason.
  - A row menu offers "Mark as secret / setting / build-time", which runs `env:mark`.
  - The new-row draft asks `--classify`.

### 4. Scaling to more frameworks
- Matrix test: for every `AppFramework` case, the classifier puts its prefix keys in `build`, never puts a secret in `build`, and never puts a managed key in the app's own settings.
- Framework onboarding checklist (in this file, once phase 1 lands): fill `publicEnvPrefixes()`, the env-file conventions and the managed keys, then run the matrix test.

## Verification
- After each phase, run the full CLI suite. After phase 3, run Desktop's suite.
- Live check on `hello-cloud`: `dotenv:push staging` gives the same ConfigMap and Secret key sets as before phase 1 (57 settings, 10 secrets). After phase 3, Desktop's table matches the CLI JSON row for row.
