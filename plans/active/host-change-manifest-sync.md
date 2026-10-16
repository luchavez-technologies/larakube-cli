# Fix: a host/blueprint change can ship to CI without the K8s manifests that reflect it

## Context

You hand-edited `.larakube.json`'s `hosts.web` (`cli.larakube.app` →
`docs.larakube.app`), committed, and pushed, then asked whether that alone
would update the live host. I traced the full path and it won't — and the
root cause isn't really the manual edit. Two things combine to make this a
real, general gap:

1. **`cloud:configure {env} --only=hosts`** — the *official* command for
   this (`ConfiguresCloudEnvironment::configureHosts()`,
   `cli/app/Traits/ConfiguresCloudEnvironment.php:187-203`) only calls
   `ensureHosts()` + `saveProjectConfig()`. It never regenerates
   `.infrastructure/k8s/overlays/{env}/ingress-patch.yaml` (rendered via
   `generateK8sManifests()` from `resources/views/k8s/overlays/production/ingress-patch.blade.php`,
   which reads `$config->getWebHosts($environment)`). So the manual edit
   you did and the official command produce an *identical* result here —
   neither keeps the committed manifest in sync.

2. **CI never reads `.larakube.json` at all.** The generated
   `.github/workflows/larakube-deploy-production.yml` just does
   `kubectl kustomize .infrastructure/k8s/overlays/production | kubectl apply -f -`
   — it applies whatever YAML is *already committed*, with no `larakube`
   invocation. So the only thing CI will ever see is whatever
   `ingress-patch.yaml` says, regardless of what `.larakube.json` says.

3. **`cloud:deploy`'s own "prefer git push" shortcut makes it worse.**
   When GitHub Actions is detected (`CloudDeployCommand.php:65-71`), an
   *interactive* run asks "trigger a deployment via Git push instead?"
   (default yes) and, on yes, exits immediately — before ever reaching the
   `orchestrateProjectScaffolding(...)` calls at lines 123/240 that would
   have regenerated the manifests. This is exactly the trap: a terminal
   user who takes the recommended "just git push" path gets zero
   guarantee the committed manifests match the blueprint they just edited.

**You were right that `heal` is the fix mechanism** — it already calls
`orchestrateProjectScaffolding($config, false, false)` →
`generateK8sManifests($config)`
(`cli/app/Commands/HealCommand.php:102-103`,
`cli/app/Traits/GeneratesProjectInfrastructure.php:1104+/1742+`), which is
exactly what writes a correct `ingress-patch.yaml`. The problem is only
that it's a separate, easy-to-forget manual step, not wired into the paths
that actually change a host.

**One reassuring finding**: Desktop's own "Deploy" button is *not*
affected by #3 — `CliRunner` always appends `--no-interaction`, and the
"prefer git push" branch is gated on `! $this->option('no-interaction')`,
so Desktop's `cloud:deploy` call always falls through to the full local
deploy (which does regenerate manifests via `orchestrateProjectScaffolding`
and applies them directly, bypassing CI entirely). The gap is specifically
in the terminal/CI-trusting path — the one you just took.

## Approach

### 1. `ConfiguresCloudEnvironment::configureHosts()` regenerates manifests on save

Mirror what `configureBase()` already does for a *new* environment
(`ConfiguresCloudEnvironment.php:171-179`, wrapped in `withSpin`), but
call the narrower `generateK8sManifests($config)` directly (not the full
`orchestrateProjectScaffolding`) — it's already available via the trait's
existing `use GeneratesProjectInfrastructure` (line 45), writes only
`.infrastructure/k8s/**`, and doesn't touch `Dockerfile.php`/`.env`/other
scaffolding files the way the full orchestrator (and `heal`) does. A
host-only change has no business rewriting those.

Update the success message to make the remaining manual step explicit —
right now it says `"✅ Hosts saved for '{$environment}'."`; it should say
the manifests were regenerated too and that committing + pushing (or
deploying) is still required, consistent with how `configureBase` already
phrases things for a brand-new environment.

### 2. `CloudDeployCommand`'s "prefer git push" branch checks before deferring

In the `confirm('...trigger a deployment via Git push instead?', true)`
branch (`CloudDeployCommand.php:65-71`), before printing "Action cancelled,
run git push" and returning 0:
- Call `generateK8sManifests($config)` (same narrow call as above) so the
  manifest on disk is guaranteed current.
- Check `git status --porcelain -- .infrastructure .larakube.json` (a new,
  small, local `Process::run()` check — no existing helper for this in the
  codebase, confirmed by grep). If it reports anything, warn explicitly
  before exiting: these files changed and must be committed + pushed, or
  CI will deploy the *previous* configuration. If it's clean, the existing
  "Action cancelled, run git push" message is accurate as-is.
- This never auto-commits or auto-pushes — matches the codebase's existing
  boundary (every manifest-writing command here only ever touches local
  files, never `git add`/`commit`/`push` on the developer's behalf).

### Explicitly out of scope

- Other `--only=` flags' same assumption (`configureBase`'s own comment:
  "every `up`/`env`/`heal` regenerates them" — not audited here for
  `--only=target`/`--only=registry`). Flagging it, not fixing it — the
  demonstrated, concrete bug is hosts; expanding to every flag risks scope
  creep beyond what's needed right now.
- Changing CI itself to run `larakube` and regenerate manifests
  server-side. That's a bigger, more invasive change (CI image needs the
  CLI, touches the committed workflow template every project already has)
  and isn't needed once the two gaps above are closed — CI applying
  whatever's committed is fine as long as what's committed is kept
  current at the points that change it.
- Desktop/UI changes — confirmed no backend change needed there; Desktop's
  Deploy flow already regenerates manifests correctly via `--no-interaction`
  bypassing the git-push-preferring branch entirely.

## Critical files

- `cli/app/Traits/ConfiguresCloudEnvironment.php` — `configureHosts()`
  (lines ~187-203): add the `generateK8sManifests()` call + message update.
- `cli/app/Commands/Cloud/CloudDeployCommand.php` — the CI-detected branch
  (lines ~65-71): regenerate + git-status check before exiting.
- Tests: `cli/tests/Feature/CloudConfigureConsolidationTest.php` and/or
  `CloudConfigureNonInteractiveTest.php` (both already cover `--only=hosts`)
  — extend to assert `ingress-patch.yaml`'s content reflects the new host
  after the command runs. New test on `CloudDeployCommand`'s
  git-push-preferring branch (fake `Process` for `git status --porcelain`,
  assert the warning appears only when dirty).

## Verification

- `composer format && composer analyse && composer test` (repo's standard
  gate) after the change.
- Manually confirm against the real `docs` project state once merged: the
  committed `ingress-patch.yaml` should already reflect `docs.larakube.app`
  from your current (already-pushed) `.larakube.json` — if not, this is
  also the moment to run `larakube heal` once by hand to catch this repo
  up, then commit+push that regenerated file separately from the code fix.
