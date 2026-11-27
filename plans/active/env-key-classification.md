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

### 1. The classifier (CLI, no behaviour change)
- `App\Support\EnvKeyClassifier::classify(ConfigData $config, string $env, string $key, ?string $value): EnvKeyClass` returns `{owner: managed|app, secret: bool, build: bool, reason: string}`. `reason` names the rule that decided, for debugging and for Desktop's tooltip.
- `AppFramework` data covers more than prefixes: some frameworks read single keys at build time (Laravel's Vite plugin reads `ASSET_URL` to prefix asset links, which matters for a CDN). Add a `buildEnvKeys()` next to `publicEnvPrefixes()`. Since `ce7a34b5`, `buildPublicEnvScript()` drops `ASSET_URL`; harmless while assets share the site's address.
- Order: overrides (phase 2; empty for now), component declarations and Plex Commons managed keys, `AppFramework` data, one pattern list (the union of the CLI's and Desktop's: PASS, SECRET, KEY, TOKEN, PRIVATE, CREDENTIAL, DSN), value checks.
- Move every site above onto it. `isSecretKey()` becomes a thin wrapper, then goes away. `buildPublicEnvScript()` uses `publicEnvPrefixes()` instead of `VITE_`.
- Tests: unit-test the classifier per rule. The existing DotenvPush, GhaWorkflow and EnvironmentPlexIntent tests must keep passing unchanged.

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
- Live check on `hello-cloud`: `dotenv:push staging` gives the same ConfigMap and Secret key sets as before phase 1 (43/44 settings, 10 secrets). After phase 3, Desktop's table matches the CLI JSON row for row.
