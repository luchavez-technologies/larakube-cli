# Plan: Headless-drivable `cloud:create` — non-interactive flags, `--json` output, remote OpenTofu state

> **Self-contained implementation plan — written so a fresh agent can pick this up with no other
> context.** If you want the full product story this serves, read `plans/active/larakube-cloud.md` §6 in
> the repo root (not this dir) — but you don't need to for the work below; everything required is here.

## Why this exists

LaraKube Cloud (a separate, not-yet-built hosted dashboard product) needs to run `cloud:create` and
related commands **headlessly** — inside a disposable per-job container, triggered by a web UI, with no
human at a terminal to answer prompts. Today, `cloud:create` is built entirely around Laravel Prompts
(`text()`, `select()`, `confirm()`) with no non-interactive equivalent for most of its inputs, no
machine-readable output, and local-only OpenTofu state that doesn't work for a multi-tenant hosted job
runner. This plan closes those three gaps. **It does not touch anything about how LaraKube Cloud itself
is built** — that's a separate, not-yet-started project. This is CLI-side groundwork only.

## Hard rules for this repo (restate here since a fresh agent won't have prior context)

- Always write **"LaraKube CLI"** — never the bare product name alone (trademark reasons). Exceptions:
  `larakube` as a literal command, "LaraKube Console", "LaraKube Local CA".
- Render any tabular output with Laravel Prompts' `table()` helper, never `$this->table()`.
- Pint is `./php vendor/bin/pint` — never any other invocation.
- **After making CLI edits, stop there.** Don't run `./build` yourself — the
  project owner runs the build step themselves. Just leave the edits ready for review.
- No company/client names belong anywhere in this repo (commits, code, comments, docs) — not relevant to
  this task, but a hard rule of the repo, mentioning for completeness.

## Task 1 — Non-interactive flags on the commands `cloud:create --managed` actually drives

`CloudCreateCommand::createManaged()` calls `$this->call('cloud:init:doks', ['--context' => $context])`
partway through (`app/Commands/Cloud/CloudCreateCommand.php:374`) — so making `cloud:create --managed`
headless-drivable means fixing **both** commands, not just one. Every prompt below currently calls
`text()`/`confirm()`/`select()` unconditionally, with **no flag override at all** in most cases — verified
by reading the actual source, not assumed.

### `app/Commands/Cloud/CloudCreateCommand.php`

| Method / call site | Current behavior | Add |
| :--- | :--- | :--- |
| `promptKeyPath()` (`:502`) | Always prompts for SSH private key path, default `~/.ssh/id_rsa` | `--key=<path>` |
| `promptRegion()` (`:557`) | Always prompts, default `nyc1` | `--region=<slug>` |
| `promptStackName()` (`:549`) | Always prompts, has a computed default | `--stack-name=<name>` (already slugified via `$this->slug()`) |
| `promptAdminCidr()` (`:566`) | `confirm()` whether to restrict, then `text()` for the IP — **two** prompts, no flag path at all | `--admin-cidr=<cidr>` (presence = restrict to this CIDR; absence in `--no-interaction` mode = open, matching the existing `confirm(..., default: false)` behavior) |
| `createVps()`'s inline `$size = text(...)` (`:279`) | Always prompts, default `s-1vcpu-1gb` | `--size=<slug>` |
| `createManaged()`'s inline `$size`/`$nodeCount`/`$versionPrefix` (`:342-344`) | Always prompts | `--size=`, `--node-count=`, `--k8s-version-prefix=` |
| `ensureDoToken()` (`:155`) | If no token in global config, always prompts via `text()` | See "token supply" below — this one needs more thought than a flag |

**Pattern to follow**: every prompt method should check `$this->option('xyz')` first and only fall back to
the interactive prompt when the flag is absent **and** `$this->option('no-interaction')` is false; when
`--no-interaction` is set and a required flag is missing, fail clearly (`$this->laraKubeError(...)`,
return 1) rather than hang or silently use a default that might surprise the caller. `resolveProvider()`
and `resolveTargetKind()` in the same file already do this correctly (flag wins, prompt only as fallback)
— match that pattern, don't invent a new one.

**Token supply (`ensureDoToken()`) needs special handling, not just a flag.** Today it persists into the
**global** `~/.larakube` config via `setDoToken()` — fine for a single human on their own machine, wrong
for a multi-tenant job container (see Task 3's tenant-isolation note — this gets solved for free at the
container level, so don't over-engineer here). Add a `--do-token=` flag (or read `TF_VAR_do_token` from
the environment directly, which `tofuEnvPrefix()` already partially supports) so a job container can pass
the customer's token in without ever calling `setDoToken()`/writing it to disk in a location that could
persist between jobs. Whether it also still writes to `~/.larakube` for the interactive case is fine to
leave unchanged.

### `app/Commands/Cloud/CloudProvisionDoksCommand.php`

Reached via `cloud:create --managed`'s internal `$this->call()`, so it needs the same treatment for the
whole managed-cluster flow to be headless:

- The `email` prompt (`:71-77`) — add `--email=` (validate the same way: `filter_var(..., FILTER_VALIDATE_EMAIL)`).
- `confirm('Install Traefik + Let\'s Encrypt...')` (`:91`) — under `--no-interaction`, default to
  proceeding (matches `confirm(..., true)`'s existing default) rather than blocking.
- `configureProjectEnvForCluster()`'s `askForCloudEnvironment()` call and its own `text()` for the web
  host (`:157-163`) — **this one may be out of scope**: it only fires when `$projectConfig` is non-null,
  i.e. when the command is run inside a project directory. A job-container invocation likely won't have a
  project checked out at all (LaraKube Cloud drives infra provisioning, not the project's local files) —
  confirm this assumption before adding flags here; if job-container invocations never pass a project
  context, this whole branch can stay interactive-only and just needs to not be reachable headlessly (i.e.
  make sure `$projectConfig` really will be null in that invocation mode, don't add unnecessary flags for
  a path that won't be hit).

## Task 1b — `cloud:configure --only=ci` needs the same treatment (added — this plan now serves two consumers, not one)

This plan originally scoped only `cloud:create`, written for LaraKube Cloud's job-container model (§6 of
the root plan). It turns out **LaraKube Console** (the separate, already-built `console/` app) needs the
exact same headless-execution capability for its own "Configure CI" button — see
`console/PLANS/multi-context-browser.md` for that design. Concretely: `ConfiguresCloudEnvironment` and
`GathersEnvironmentData` (the traits behind `cloud:configure`) are just as prompt-heavy as
`CloudCreateCommand` was — `select()`/`multiselect()`/`text()` throughout, no flag overrides anywhere. Add
non-interactive flags following the exact same pattern as Task 1 (flag wins, prompt only as interactive
fallback, fail clearly under `--no-interaction` when a required value is missing) for at least: ingress
controller choice, managed-services selection, additional web hosts, and the registry provider + image
path prompts in `promptRegistry()`.

**Good news, scope this separately — `cloud:deploy` is already close to headless-drivable, don't
over-invest here.** Re-read directly: its two `confirm()` calls already fall back to their stated defaults
under `--no-interaction` (Laravel Prompts' standard behavior), and unlike `CloudCreateCommand` it has no
inline unconditional `text()` prompts with no flag path. `cloud:deploy {environment} --no-interaction`
is likely already usable as-is, or needs at most a small verification pass — not the same surgery Tasks 1
and 1b require.

## Task 2 — `--json` output mode (scope: `cloud:create` only, both `createVps()` and `createManaged()`)

No machine-readable output exists anywhere in these commands today — everything is `$this->line()`/
`laraKubeInfo()`/spinners for a human terminal. A job-container caller needs a single structured result to
parse instead of scraping colored terminal text.

**Recommended pattern**: add a `--json` flag to `cloud:create`'s signature. When set:
- Suppress the human-readable `$this->line()`/spinner output (or route it to stderr instead of stdout, so
  stdout stays clean JSON-only — simpler than conditionally suppressing every individual call site).
- At the end of `createVps()`/`createManaged()`, emit one JSON object to stdout via
  `$this->line(json_encode([...]))` (or add a small `jsonOutput(array $data): void` helper to the
  `LaraKubeOutput` trait if this pattern is going to be reused by other commands later — reasonable to add
  now since `cloud:migrate` will need the identical treatment once it exists, but don't build machinery for
  commands that don't exist yet, just make the one helper reusable).
- Shape: `{"success": true, "stackName": "...", "kind": "vps"|"managed", "ip": "..."|null, "context": "..."|null, "error": null}` on success; `{"success": false, "stackName": "...", "error": "<message>"}` on failure. Match field names to what's already used internally (`$stackName`, `$ip`, `$context` variables already exist in both methods) rather than inventing new terminology.

Out of scope for this task: don't add `--json` to any other command. `cloud:migrate` and `cluster:grant`
will need the same treatment later, once they're actually being built — the helper above should make that
cheap when the time comes, but don't preemptively touch those commands now.

## Task 3 — Remote OpenTofu state (opt-in, not a default change)

Read `app/Traits/InteractsWithOpenTofu.php` in full before starting — the facts below come directly from
it, not assumption:

- State is **100% local today**: `tofuWorkdir($stack)` = `home_path('.larakube/tofu/'.$stack)`, and
  `terraform.tfstate` lives directly in that directory (`tofuStateExists()`, `:182-187`). There is no
  `terraform { backend ... }` block anywhere — the generated HCL templates
  (`resources/views/tofu/do/vps.blade.php`, `.../managed.blade.php`) never declare one, so OpenTofu/
  Terraform defaults to local state.
- **`TF_IN_AUTOMATION=1` is already set** on every invocation (`tofuEnvPrefix()`, `:244`) — the
  non-interactive-provider-install behavior this implies is already correct for headless use, nothing to
  change there.
- **State encryption (OpenTofu only) already exists** via `TF_ENCRYPTION`, keyed by a per-stack PBKDF2
  passphrase stored in the global config (`tofuEncryptionEnv()`, `:193-220`, `ensureTofuPassphrase()` on
  the global config object). This is a **separate concern from remote state** — encryption-at-rest and
  *where* the state file lives are independent. Don't conflate them; keep the encryption mechanism as-is.
- **Tenant isolation is already solved, no code change needed.** `home_path()` (`app/helpers.php:9`)
  reads `$_SERVER['HOME']`/`getenv('HOME')` — confirmed by reading it directly. A disposable per-job
  container just needs `HOME` set to a fresh scratch directory, and `~/.larakube` (global config, DO
  token, stack registry, Tofu workdir) is naturally isolated per job with zero CLI changes. Don't build
  a `--config-dir=` override or similar — it's redundant with something the container runtime already
  gives you for free.

**What actually needs to change**: add an **opt-in** S3-compatible remote backend, only when explicitly
configured — the existing local-state behavior must stay the default for CLI-only/personal use (someone
running this on their laptop for their own projects shouldn't be forced into a remote backend they don't
need or want).

1. Add a flag or env-var trigger (e.g. `--remote-state-bucket=<bucket>` and `--remote-state-endpoint=<url>`,
   or read `LARAKUBE_TOFU_STATE_BUCKET`/`LARAKUBE_TOFU_STATE_ENDPOINT` from the environment — prefer env
   vars if this is only ever going to be driven by a job container, since that avoids a job-orchestration
   layer needing to pass yet another CLI flag per invocation).
2. When present, `writeTofuFiles()` should also write a `backend.tf` alongside `main.tf`, containing an S3
   backend block scoped to that stack: bucket + a per-stack key (e.g. `tofu-state/<stack>/terraform.tfstate`)
   + the endpoint/region for whatever S3-compatible store is used (DO Spaces is a reasonable default given
   the rest of this codebase's DO-first posture, but keep the endpoint configurable, not hardcoded).
3. **Use OpenTofu's native S3 locking (`use_lockfile = true` in the backend block)** rather than the older
   Terraform pattern of a separate DynamoDB lock table — this is a real OpenTofu 1.7+ feature, simpler to
   operate (no second piece of infrastructure), and this codebase already prefers OpenTofu over Terraform
   (`resolveTofuBinary()` tries `tofu` first). Confirm the installed OpenTofu version supports it before
   relying on it; fall back to documenting the DynamoDB-table approach only if the version constraint turns
   out to be a real blocker.
4. Backend credentials (the S3-compatible store's own access key/secret, distinct from the customer's DO
   token) need to reach the `tofu init`/`apply` calls — extend `tofuEnvPrefix()` to inject them from
   equivalent env vars when the remote-backend trigger is present, following the same pattern already used
   for `TF_VAR_do_token`.
5. **Whose bucket is this?** Recommend this is LaraKube Cloud's **own** operational infrastructure (like
   how Terraform Cloud/Spacelift own state storage centrally, one bucket + per-stack key prefixes, not a
   bucket per customer) — it's bookkeeping for how LaraKube Cloud manages a customer's infra, not something
   the customer needs to see or own. This means the actual bucket + credentials are an operational/deploy
   concern for whoever runs LaraKube Cloud's job containers, not something this CLI-side task needs to
   provision — just make sure the CLI accepts them via flag/env var cleanly.

## Verification

No live cloud resources need to be created to verify Tasks 1–2 — confirm each flag actually bypasses its
corresponding prompt by running the command with `--no-interaction` plus the new flags and checking it
doesn't hang waiting on stdin. For Task 3, a real `tofu init` against a real S3-compatible bucket (e.g. a
throwaway DO Spaces bucket) is worth doing at least once to confirm the backend block is syntactically
correct and locking actually works — but this is the one part of this plan that costs real money to test
end-to-end (matches the project owner's own note that provider testing incurs real fees), so don't run it
repeatedly; get it right on paper first, verify once.

## A question that'll come up: is a 60MB+ binary feasible for a per-job disposable container?

Yes — addressed here so it isn't re-litigated later. The binary isn't fetched over the network per job; it
gets baked into a pre-built job-container image at build time (binary + `tofu`/`kubectl`/`ssh` client),
pushed to a registry once, and Kubernetes caches the image **at the node level** — every job scheduled on
a node that's already run one pays zero additional pull cost. Even a full cold pull (realistically
150–400MB once the base OS + tofu + kubectl are included, not just the 60MB binary alone) is low
single-digit seconds — a rounding error against the minutes an actual droplet provision + k3s install
takes. For scale: `terraform` itself (the tool this wraps) is commonly 80–100MB+ as a static binary; a
typical application container image is 200MB–1GB+. 60MB is ordinary, not a size that needs special
handling. The one real nuance: this only pays off if jobs land on nodes that have run a job before — a
fully ephemeral, fresh-node-per-burst autoscaling setup pays one pull per new node. Not a blocker, but a
reason to run the Cloud control cluster (§6 of the main plan) on a moderately stable node pool rather than
maximally ephemeral nodes, at least until job volume is high enough to justify aggressive churn.

## Explicitly out of scope

- Anything about LaraKube Cloud's own dashboard, job queue, or Kubernetes-Jobs-based worker infrastructure
  — that's a separate, not-yet-started project.
- `cloud:migrate`, `cluster:grant`, `monitor:init` non-interactive/`--json` treatment — those commands
  either don't exist yet or haven't been scoped for this treatment; don't preemptively touch them.
- Any pricing/licensing/entitlement logic — unrelated to this plan.
