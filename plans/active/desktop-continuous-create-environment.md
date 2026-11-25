# Desktop: one continuous "Create environment" flow

## Problem
Creating a cloud environment is split in two places:
- **`AddEnvironmentDialog`** (`desktop/resources/js/pages/projects/show.tsx`): name, server, web host, backing services. It posts to `link` (`cloud:configure <env> --only=target`).
- **`EnvironmentSettingsDrawer`** (`desktop/resources/js/components/environment-settings-drawer.tsx`): CI/CD, registry, secrets, and the `.env.<env>` push. It is a separate drawer with its own Save buttons per section ("Save Address", "Save Backing Services", "Update Pipeline & Secrets").

Two problems follow:
- Users can't tell what is done, what is pending, or what they still need to do before the first deploy works.
- There is no Reverb host field.
- The `.env.<env>` push lives elsewhere (`projects.dotenv.push`).

## Goal
One guided flow from top to bottom, with the same layout for create and edit:

1. **Name.**
2. **Server.** Pick a server; ready servers only.
3. **Addresses.** Web host is required. A Reverb host is shown only when the project uses Reverb. Both use the subdomain + connected-domain picker, with the ExternalDNS badge.
4. **Backing services:**
   - **Simple mode (default):** auto-pick. Use Plex Commons when the service is active on that server, otherwise own pod. Show the result as a one-line summary with a "Customize" link.
   - **Advanced:** the per-service Own pod / Plex Commons toggles that exist today.
5. **Deploy method:** a toggle, "Deploy with CI/CD".
   - **Off:** deploy from this computer (`cloud:deploy`).
   - **On:** expands to forge, remote, registry, branch and image, from the drawer's existing pipeline section.
     - Also shows a **"Push .env.<env> to the cluster now"** row with its status (`projects.dotenv.status`). The first CI deploy picks it up.
6. **Review & create:** a summary of everything above. One button runs the steps in order. Each step shows a live tick or cross, using existing CLI runs:
   1. `cloud:configure <env> --only=target --context= --web-host= [--host=reverb=…]`
   2. `cloud:configure <env> --only=plex --plex=...`
   3. If CI is on: `cloud:configure <env> --only=ci ...`
   4. If chosen: `dotenv:push <env>`

   If a step fails, the remaining steps stop, and the user gets "Retry from here". Every verb is idempotent (AGENTS.md), so retrying is safe.
7. **Finish:** "Deploy now" (local or CI), or "Done".

## What the LaraKube CLI already supports (checked in code)
- **Server and web address:** `cloud:configure <env> --only=target --context= --rebind --web-host= [--web-hosts=] [--ssh-key=] [--ingress=] [--managed=] [--plex=]`.
  - Desktop's `link` route already calls this. `--only=target` deliberately skips CI/Commons so a failure there can't mark the binding as failed.
  - `env <env>` also creates environments, but Desktop avoids it because it does nothing on an existing environment.
- **Backing services:** `cloud:configure <env> --only=plex --plex=a,b` (an empty `--plex=` means none). Desktop's `projects.backing-services` already calls it.
- **CI/CD:** `cloud:configure <env> --only=ci --platform= --registry= --image= --branch=`, plus audit flags (`--skip-audit`, `--strict`, `--no-trivy`, and others). Desktop's `projects.ci` already calls it.
- **Pushing the env file:** `dotenv:push <env> [--context=] --json`.
  - It writes `laravel-secrets`/`laravel-config` in the env's namespace, through OpenBao when present.
  - It also pushes `{ENV}_BUILD_ENV` to CI when that env has a workflow.
  - It **fails with "No '.env.<env>' on disk"** when the file doesn't exist.

## Gaps found in the LaraKube CLI
1. **Reverb's address can't be set without a prompt.** `PromptsForHosts` asks for each `HasPromptableHosts` service with a bare `text()` (Reverb WS, S3/CDN), and no flag answers it. When nobody answers, the host is derived from the web host.
   - **Fix:** add `--host=<service>=<hostname>` to `cloud:configure`; it can be repeated. `PromptsForHosts` uses it before prompting.
   - This is generic: it covers Reverb and object storage, and any future client-facing service.
   - Desktop shows one field per promptable service, so it doesn't hardcode Reverb. Desktop needs the list of those services; get it from the project's `--json` output, adding it if it's missing.
2. **`.env.<env>` is created by `env <name>`, by copying the local `.env`, so it starts with LOCAL hosts.**
   - `cloud:deploy` has the correct seed, but only when the file is missing: `syncEnvFile($path, $config->getAllEnvironmentVariables($env), false, $env)`, which writes that environment's values.
   - Desktop creates environments with `cloud:configure --only=target`, which never writes the file, so "push now" would fail with "No .env.<env> on disk".
   - **Fix (no new command):**
     - Move `cloud:deploy`'s seed into one shared trait method, `seedEnvironmentEnvFile($config, $env)`. It writes the file only when it's missing.
     - Call it from `env <name>`, replacing the `copy()` of `.env`, from `cloud:configure --only=target`, and from `cloud:deploy`, whose own copy is removed.
     - The static-site path keeps its own copy with its warning, because VITE values are compiled in and that path tells you to run `data:wire`.
   - The create flow then opens Desktop's existing dotenv editor (`projects.dotenv.update`) on the seeded file before pushing.
3. **The env's namespace may not exist yet.** It's created idempotently (`create namespace --dry-run | apply`) only by:
   - `cloud:configure --only=ci`: `setupRegistrySecret`, `uploadGhaSecrets`;
   - `cloud:deploy`: `deployViaSshSideload`, `deployViaRegistry`.

   `dotenv:push` never creates it, so pushing before CI or the first deploy fails.
   - **Fix:** `dotenv:push` runs the same idempotent namespace apply before writing. A shared `ensureNamespace()` in `InteractsWithRemoteDeploy` replaces the four inline copies.
   - With that, the create flow's order holds: target, plex, ci, then dotenv push.

## Approach
- **Extract the drawer's sections** (server, address, backing services, pipeline) into shared components under `desktop/resources/js/components/environment/`. Both the create flow and the settings drawer then render the same pieces:
  - **Create:** a stepper with Next/Back and one submit at the end.
  - **Edit:** the current per-section Save buttons.

  This removes the duplicated code that differs today between `AddEnvironmentDialog` and the drawer.
- **New Desktop endpoint `POST /projects/{project}/environments`** (ProjectController@createEnvironment):
  - Validates the whole form.
  - Queues the steps above as one sequenced `CliRunner` run group, so the UI shows step progress through the existing run-status polling.
  - It queues `cloud:configure --only=target [--host=…]`, then `--only=plex`, then `--only=ci` if CI is on, then `dotenv:push --json` if the push is chosen (the file is already seeded by step `target`).
  - These reuse the argument building that `link`, `configureBackingServices` and `configureCi` already have; move it into one shared builder class rather than duplicating it.
- **Simple-mode auto-pick lives in the backend:** the same `eligibleBackingServices` and server Plex status, so CLI/Desktop agree. Advanced mode sends explicit `--plex=`.
- **Reverb detection** comes from the project blueprint (the Reverb service present), the same source the overview cards already use.

## Build order
1. **LaraKube CLI:**
   - `--host=service=hostname` on `cloud:configure` (plus `env`, which shares `PromptsForHosts`).
   - `seedEnvironmentEnvFile()`, shared by `env`, `cloud:configure --only=target` and `cloud:deploy`.
   - `ensureNamespace()`, used by `dotenv:push` and the four existing call sites.
   - One Pest file per command.
2. **Desktop:**
   - The shared builder class.
   - The `createEnvironment` endpoint and its tests.
3. **Desktop UI:**
   - Shared section components.
   - The step-by-step create flow.
   - The settings drawer switched to the shared sections.

## Out of scope
- Runner/Cloud changes.
- Restyling the overview page.

## Verification
- **Pest:** the endpoint builds the right ordered CLI arg lists for:
  - simple mode and advanced mode;
  - CI on and off;
  - dotenv push on and off;
  - Reverb present and absent.

  It also stops queuing after a failed step.
- **Vitest/TS:** typecheck only (`npm run types`). One heavy process at a time.
- **Manual in Desktop:**
  - Create `staging` on luchtech-vps with simple mode, CI on and push `.env.staging`. Every step ticks, and the settings drawer then shows the same values.
  - Re-run create with the same name and get an idempotent no-op or a clear "already exists".

## The pause between `env` and `cloud:configure` is on purpose
On the CLI, the gap between `env staging` and `cloud:configure staging` is where you put each environment's own keys into `.env.staging`: test PayMongo keys for staging, live keys for production. Desktop must keep that pause as its own step, not skip it.

- **Seed (LaraKube CLI):** `seedEnvironmentEnvFile()` writes two kinds of keys:
  - **Keys LaraKube manages:** `APP_ENV`, `APP_URL`, `DB_*`, `REDIS_*`, S3, Reverb, and others. These come from `getAllEnvironmentVariables($env)`.
  - **App keys:** every other key in `.env`, with its **value blanked**, never the local value, so a test or local key can't end up in production by accident.
  - `--json` output lists which keys are managed and which are app keys, so Desktop can show them differently.
- **Desktop step "Environment variables"** sits between Review and Push. It uses the existing dotenv editor (`projects.dotenv.update`), with three groups:
  1. **Needs a value:** app keys that are still blank. It shows the key name and a "copy from…" action that pulls the value from another environment's file (local/staging/production). Copying is never automatic. It is offered for every environment, production included (user decision).
  2. **Managed by LaraKube:** read-only by default, with an "override" toggle.
  3. **Filled:** keys that already have a value.
- **Push gate:** "Push .env.staging" is enabled only when "Needs a value" is empty, or the user ticks "Leave these blank". The push button lists the keys it is about to send.
- **No-CI path is the same:** `cloud:deploy` already pushes the file during deploy, so this step also appears when CI is off.
- **Editing later:** Environment Settings gets the same variables section, so new keys added later, such as a new payment provider, follow the same flow.

## Additions from the staging + production walkthrough
1. **`APP_KEY` is managed and generated for each environment.**
   - `getAllEnvironmentVariables()` doesn't include it, so under the "blank app keys" rule it would be pushed empty, and Laravel won't boot.
   - The seed generates a fresh `base64:` key for each environment. It's never copied from local or between environments, because a shared key would let environments decrypt each other's data.
   - It's shown as managed (read-only, with an override toggle).
2. **`APP_ENV` and `APP_DEBUG` are guaranteed by the seed.** `APP_ENV=<env>`. `APP_DEBUG=false` for every non-local environment, unless overridden. Check `getAllEnvironmentVariables()`, and add them if missing.
3. **Defaults depend on the environment's name:**
   - **Simple mode** uses own pods for `production` (with a one-line "why"). Other environments use Plex Commons when it's on the server.
   - **CI branch** defaults to `main` for `production`, and to the environment's name otherwise.
4. **DNS step in Review.** When the server's ExternalDNS doesn't manage the chosen domain, Review shows plainly: "Add an A record `<host>` → `<server IP>` before deploying". It includes a re-check button that uses the existing DNS lookup.
5. **Drift between environment files.** Environment Settings shows "N keys in `.env` aren't in `.env.<env>`" and lists them in the same "Needs a value" group, so a key added later locally isn't silently missing in staging or production. Desktop compares key names only, never values.

## Third backing-service choice: outside the cluster (e.g. AWS RDS)
The LaraKube CLI already has this as `--managed=` ("externally-managed services") on `env` and `cloud:configure`. The Desktop UI only offers Own pod / Plex Commons today.

- **Per-service toggle becomes three options:** Own pod / Plex Commons / **External**. The CLI command is `--managed=postgres,...` and `--plex=...` together. A service can't be in both; check that the CLI rejects that.
- **External service in the variables step:**
  - Its connection keys (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and `DB_SSLMODE` where relevant) move from "Managed by LaraKube" to **"Needs a value"**.
  - They get an "External PostgreSQL" heading and a hint, e.g. "your RDS endpoint".
- **Simple mode never picks External.** It's only offered under Customize, because it needs details only the user has.
- **Review shows a reachability reminder:** "The server must be able to reach `<DB_HOST>:<port>`, so check the RDS security group or allowlist." Optionally there's a "Test connection" button that runs a one-off pod on the cluster. It's a later step; it needs a CLI verb (e.g. `data:check <env>`), and the plan for that is still to come.
- **To verify in code before building:**
  - What `--managed` currently does to the manifests: no Deployment or Service for that service.
  - What it does to the seeded env values: are the `DB_*` values left out or set to placeholders?
  - Whether `dotenv:push` sends them as Secrets.

## Verified in code (corrections)
- **`APP_ENV`/`APP_DEBUG` are already handled.** In `ConfigData::getAllPublicEnvironmentVariables()` (around line 1707), every cloud environment gets `APP_ENV=production` and `APP_DEBUG=false`. This is deliberate: staging must also report "production" so Laravel's safeguards apply. **Walkthrough item 2 is withdrawn; do not set `APP_ENV=<env>`.**
- **What `--managed` does today:**
  - **Manifests:** correct. The cloud overlay emits delete-patches for the service's Deployment and Service and skips its volumes (`GeneratesProjectInfrastructure` around line 1350). Wait-for is skipped too (`getCoreDependencies`).
  - **Env values:** wrong for RDS. `DatabaseDriver::getPublicEnvironmentVariables()` still sets `DB_HOST` to the in-cluster FQDN (`getInternalFqdn`), and the secret variables set `DB_PASSWORD=larakubesecretpassword`. **Fix:** in `getAllEnvironmentVariables($env)`, a service listed in `getManaged($env)` (not `getPlex`) gets its connection keys emitted blank, so the Desktop editor lists them under "Needs a value". Check the same for Redis (`CacheDriver`) and S3 (`StorageDriver`).
- **Plex + managed overlap is not rejected.** `getExternallyHosted()` just merges the two lists, and `GathersEnvironmentData` (around line 76) only validates option names. **Fix:** fail with a clear message when a service is in both `--managed` and `--plex`.

## Task list for Antigravity (mechanical, LaraKube CLI only)
Rules: no PHPStan, no `./build`, no commits. Run only the Pest files named below, plus `pint` on changed files. Write a report to `plans/active/desktop-continuous-create-environment.report.md`.

1. **DONE (Claude, uncommitted):** `EnsuresNamespace` trait, used by InteractsWithRemoteDeploy, ConfiguresCloudEnvironment and dotenv:push; test added. Was: in `InteractsWithRemoteDeploy`:
   - It runs `create namespace --dry-run=client -o yaml | apply -f -`.
   - Replace the inline copies in `InteractsWithRemoteDeploy` (around lines 713 and 810) and `ConfiguresCloudEnvironment` (around lines 866 and 941).
   - Call it in `DotenvPushCommand` before writing to the cluster.
   - Test: `tests/Feature/DotenvPushCommandTest.php`, asserting the namespace apply runs.
2. **`seedEnvironmentEnvFile(ConfigData $config, string $env)`**: a shared trait method, reachable from `env`, `cloud:configure` and `cloud:deploy`.
   - It does nothing if `.env.<env>` exists. Otherwise it writes `getAllEnvironmentVariables($env)`, plus every other key from `.env` with an empty value, plus a fresh `APP_KEY=base64:<32 random bytes>`.
   - Use it in:
     - `EnvCommand`, replacing the `copy()` around line 68;
     - `CloudDeployCommand` around line 132, replacing its inline seed. Keep the static-site path's `copy()` and its warning as they are;
     - `cloud:configure --only=target`, after the target is saved.
   - Test: one new file, `tests/Feature/SeedEnvironmentEnvFileTest.php`. Cover: app keys blank, `APP_KEY` freshly generated and different from local, an existing file untouched.
3. **Managed services emit blank connection keys** (see corrections above), for the DB, Redis and S3 drivers. Extend the existing driver tests.
4. **Reject a service in both `--managed` and `--plex`** in `GathersEnvironmentData`, with a test.
5. **`--host=service=hostname`** (repeatable) on `cloud:configure` and `env`:
   - `PromptsForHosts` uses the flag's value before the `text()` prompt.
   - An unknown service name fails with the list of valid ones (`HasPromptableHosts`).
   - Test in the existing `cloud:configure` test file.

**Left for Claude:** the Desktop endpoint, the shared argument builder, the UI, and the `--json` that lists managed vs app keys. Claude reviews everything above and then runs the full suite.

**Note for task 4:** a check added at the end of `gatherEnvironmentData()` did NOT fire for `cloud:configure --only=target --managed=postgres --plex=postgres,...`. The test stopped at an unanswered `askQuestion` instead. So `configureBase` (in `ConfiguresCloudEnvironment`) collects managed and plex through a different path. Put the check where both lists are final; one shared helper covers `env`, `cloud:configure`'s target and plex steps, and `--only=plex` against an environment's existing `managed`. Also find which prompt fired: the test passes every flag, so something on that path still prompts headlessly.

**Task 5 status (Claude, uncommitted, untested):**
- **Done:**
  - `--host=*` added to `cloud:configure` and `env`.
  - `PromptsForHosts::promptForHosts()` uses it: it validates `service=hostname`, rejects unknown services, and has a private `hostOverridesFromFlag()`.
- **Still to do:**
  - `cloud:configure --only=target`/`hosts` and `cloud:deploy` ask for client-facing hosts through a DIFFERENT path, `EnsuresRealHosts::ensureHosts()` (the confirm/text around lines 136–152). Make it use `hostOverridesFromFlag()` too: move the method to a small shared trait, and use the flag value instead of the confirm and the text prompt.
  - Then add tests. Two tests in `EnvironmentPlexIntentTest` (unknown service, missing `=`) hit an unanswered `askQuestion` and were removed: on the `--only=target` path the exception never fires before a prompt. That same path is why the task 4 attempt failed. `configureBase` builds `EnvironmentData` itself (`ConfiguresCloudEnvironment` around lines 97–122), not through `gatherEnvironmentData()`, so task 4's conflict check belongs there too.
- **Update:** `EnsuresRealHosts::ensureHosts()` now uses a non-empty `--host` value instead of the confirm and the text prompt. It goes through `hostOverridesFromFlag()`, now protected, and is guarded by `method_exists` because `cloud:deploy` has no `PromptsForHosts`.
- **Remaining for task 5:**
  - Tests: a project with Reverb plus `--only=target --host=reverb=ws.example.com` saves the host without prompting.
  - Unknown-service validation in this path.
