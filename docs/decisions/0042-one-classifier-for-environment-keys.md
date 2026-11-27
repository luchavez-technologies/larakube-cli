# 0042: One Classifier for Environment Keys

**Status:** Proposed

## Context

Every key in an app's `.env.<env>` needs three answers before it can be delivered: who owns it, whether it is secret, and whether the image build needs it. Those answers were computed in at least five places, each with its own rules:

- `ReadsEnvSources::isSecretKey()` and `hasEmbeddedCredentials()` in the LaraKube CLI;
- `inferIsSecretKey()` in LaraKube Desktop's `environment-variables-table.tsx`, a separate list that differed from the CLI's;
- `ConfiguresCloudEnvironment::buildPublicEnvScript()`, which hardcodes `VITE_` although `AppFramework::publicEnvPrefixes()` exists;
- `PushesBuildEnvToCi::buildEnvPairs()`;
- `ConfigData::getPlexManagedKeys()`.

When they disagree, values go to the wrong place. In one session: a service account key went to a ConfigMap because its name matched no pattern, 39 runtime settings were baked into an image build, and Desktop showed "Local Only" for values nothing would ever push. Each framework LaraKube adds (about 15 are planned) brings its own build-time prefix and conventions, and would have to be taught to every one of those places.

Listing every key in `.larakube.json` was considered and rejected: it duplicates `.env.example`, goes stale, and makes every app maintain a list LaraKube can mostly infer.

## Decision

1. **Every key has three answers:**
   - **owner:** `managed` (LaraKube derives the value from the blueprint or a component: `DB_HOST`, `APP_URL`, Plex Commons credentials) or `app`. Managed values are never taken from a local file over LaraKube's own.
   - **sensitivity:** `secret` goes to OpenBao, then the app Secret; `setting` goes to the app ConfigMap (ADR 0041).
   - **phase:** `runtime` (delivered by `dotenv:push` only) or `build` (also written into the image build's `.env` and `<ENV>_BUILD_ENV`).
2. **One classifier in the LaraKube CLI answers all three,** and every consumer uses it: `dotenv:push`, workflow generation, CI build env, and the `.env.<env>` seed. It resolves each key in this order:
   1. **project overrides** in `.larakube.json` (`envKeys`), written only by `larakube env:mark`;
   2. **component declarations** (`HasEnvironmentVariables`, Plex Commons managed keys), which make a key `managed`;
   3. **framework data** on `AppFramework` (`publicEnvPrefixes()` and any future framework facts);
   4. **one list of name patterns;**
   5. **value checks:** credentials in a URL, a PEM block, a service account's `private_key`.
3. **`.larakube.json` holds exceptions only:** `envKeys: {KEY: {secret?: bool, build?: bool}}`, project-wide. Values stay in `.env.<env>`.
4. **LaraKube Desktop does not classify.** It shows the CLI's answer from `larakube dotenv <env> --json` and changes it through `env:mark`.
5. **Adding a framework means adding data to `AppFramework`,** covered by one matrix test that runs the classifier over every framework. Nobody adds a rule list anywhere else.

## Consequences

- Delivery bugs have one place to be fixed, and the CLI and Desktop can no longer disagree.
- A key the rules get wrong is fixed per project with `env:mark`, not by renaming it.
- Managed keys become visibly read-only in Desktop.
- Until it is built, any change to how keys are classified must go into the code listed above consistently, and should move toward this design, not away from it. The plan is `plans/active/env-key-classification.md`.
