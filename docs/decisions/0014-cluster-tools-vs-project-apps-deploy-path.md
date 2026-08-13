# 0014 — Cluster Tools (`*:init`/`*:wire`) deploy by direct `kubectl apply`; project apps (`new`/`cloud:deploy`) go through CI/CD

**Status:** Accepted (2026-08-14)

## Context

LaraKube CLI has two categories of "things running in the cluster" that look
similar from the outside — both end up as a Deployment behind an Ingress —
but are built and redeployed by entirely different mechanisms:

1. **Cluster Tools** (the `ClusterTool` / `DataTool` enums): shared
   infrastructure like Directus, PocketBase, Zitadel, Stalwart, Penpot,
   Vaultwarden, monitoring. Deployed by that tool's `:init` command (e.g.
   `data:init`, `mail:init`, `sso:init`) or reconfigured by a `:wire` command
   (e.g. `sso:wire`). These commands render a Blade template that ships
   *inside the CLI binary itself* (`resources/views/k8s/**`) and apply it
   directly: `DataInitCommand::deployData()` calls
   `view('k8s.data.shared', [...])->render()`, writes it to a temp file, and
   runs `kubectl apply` via `applyAndVerifyRollout()` — see
   `app/Commands/Data/DataInitCommand.php`. No git repo, no Dockerfile, no CI
   pipeline is involved anywhere in this path. The container image (e.g.
   `directus/directus:12`) is a pinned, vendor-published image — never built
   from source.

2. **Project apps** (`new`, `cloud:deploy`): the user's own Laravel/Inertia
   application, scaffolded into its own directory with its own git repo,
   Dockerfile, and CI workflow file (`GeneratesProjectInfrastructure`,
   invoked from `NewCommand`). Redeploying a code change here means: build an
   image from the user's source, push it to a registry (or SSH-sideload it
   on a no-registry VPS — see [[project_cloud_deploy_remote_image]]), and
   have CI/CD apply the result. There is no equivalent of "just re-run
   `cloud:deploy` and it kubectl-applies a template" — the template only
   describes the *shape*; the *content* is the user's own built image.

The distinction matters because the two categories have **different correct
redeploy procedures**, and conflating them produces wrong advice:

- For a **project app**, [[feedback_no_manual_kubectl]] is the load-bearing
  rule: never patch the live Deployment by hand, always fix the code, then
  go through `larakube heal` → `cloud:configure --only=ci` → push → CI/CD,
  because the live object's truth is "whatever the last successful pipeline
  run built and applied," and a manual patch or an out-of-band `kubectl
  apply` diverges from that history invisibly.
- For a **Cluster Tool**, there is no pipeline to divert from. The `:init`/
  `:wire` command *is* the direct-apply mechanism, on purpose — that's how
  `data:init` has always worked, and how every other tool's `:init` works.
  Editing the tool's Blade template in `resources/views/k8s/**`, rebuilding
  the CLI (`./php vendor/bin/pint && ./build`), and re-running the same
  `:init`/`:wire` command **is** the fix-forward path. Telling a user to
  "push and let CI/CD deploy it" for a Cluster Tool is simply wrong — there
  is no CI/CD in that path to push to.

This surfaced concretely 2026-08-14: after fixing a resource-limit bug in
`resources/views/k8s/data/directus.blade.php`, the recommended next step was
mistakenly described in CI/CD terms, even though `data:init` — the command
that owns this exact manifest — applies it directly and was sitting one
function call away in the same file just read moments earlier.

## Decision

1. Any manifest under `resources/views/k8s/**` belongs to a Cluster Tool.
   Its owning `:init`/`:wire` command is authoritative for how it reaches
   the cluster: rebuild the CLI, re-run that command. Never describe this
   as "push and CI/CD will deploy it" — there is no CI/CD for Cluster Tools.
2. A user's own application code (inside a `larakube new`-scaffolded project
   directory, with its own git repo and Dockerfile) is a **project app**.
   [[feedback_no_manual_kubectl]]'s CI/CD-only redeploy rule applies there,
   not to Cluster Tools.
3. Before recommending a redeploy mechanism for something running in the
   cluster, check which category it is by locating the command that owns
   it — grep `resources/views/k8s/**` for the template, or check whether the
   namespace/Deployment is a `ClusterTool`/`DataTool` enum member — rather
   than defaulting to whichever mechanism was most recently discussed.

## Consequences

- "How do I redeploy this" has a one-line answer once you know the
  category: Cluster Tool → re-run its `:init`/`:wire` after a CLI rebuild;
  project app → `cloud:deploy` / CI pipeline.
- A manual `kubectl apply`/`kubectl set env` against a Cluster Tool's own
  Deployment, issued *by its owning `:init`/`:wire` command*, is not the
  violation [[feedback_no_manual_kubectl]] warns about — that rule is about
  bypassing a project app's pipeline, not about how Cluster Tools have
  always worked. Manual, ad hoc `kubectl` from a terminal (or from an agent
  improvising outside any `:init`/`:wire` command) to patch a Cluster Tool
  is still exactly the thing to avoid — fix the template and re-run the
  command instead.
