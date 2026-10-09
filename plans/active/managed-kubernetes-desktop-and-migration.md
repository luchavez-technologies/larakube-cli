# Managed Kubernetes (GCP/AWS) in Desktop + k3s→Managed Migration

## Context

The user wants to give a solo dev whose project outgrows a tiny k3s VPS a path to GCP/AWS managed Kubernetes (GKE/EKS), and asked how that meshes with the current Desktop UI and whether migrating an existing k3s-hosted project to a managed cluster is realistic. Research (two Explore agents + direct file reads) found the premise was more solved than expected: **the CLI already has a full, working managed-cluster pipeline** — the real gap is almost entirely in Desktop's exposure of it, plus one genuine cross-cluster migration gap. Related but distinct: [managed-k8s-overlay-compatibility.md](./managed-k8s-overlay-compatibility.md) covers making *generated manifests* portable to a managed cluster a project already has (namespace/SA/ingress-annotation overrides, mostly shipped); this plan covers *creating* a managed cluster from Desktop in the first place, and *moving* a project from one cluster to another.

## What already exists (verified directly, not assumed)

- `cloud:create --managed --provider=gcp|aws|do` is a complete OpenTofu pipeline today (`app/Commands/Cloud/CloudCreateCommand.php::createManaged()`, ~line 744): real Tofu templates (`resources/views/tofu/{do,gcp,aws}/managed.blade.php` — `google_container_cluster`, `aws_eks_cluster`+`aws_eks_node_group`+IAM, `digitalocean_kubernetes_cluster`), per-provider managed sizing (`App\Enums\CloudProvider::managedSizes()`/`defaultManagedSize()`), and dedicated post-provision installers `cloud:init:gke`/`cloud:init:eks`/`cloud:init:doks` that install Traefik + Let's Encrypt and bind the project environment.
- Storage class auto-defaults per managed provider (`App\Enums\ManagedProvider::defaultStorageClass()`: GKE→standard-rwo, EKS→gp3, DOKS→do-block-storage), auto-applied via `ResolvesEnvironmentContext::recordManagedTarget()`, which also auto-detects live node count to pick single-node vs multi-node-HA strategy.
- `App\Enums\ManagedProvider::haOption()` already models HA as boolean (DOKS/LKE), always-on (EKS/GKE), tiered (AKS), or unknown (custom) — `CloudCreateCommand::promptHaControlPlane()` already defaults `--ha` off non-interactively and skips the prompt entirely where it isn't a real choice.
- Ingress: LaraKube only ever installs Traefik, even on managed clusters (`cloud:init:gke`/`eks`/`doks` all call `installTraefik()`). `App\Enums\IngressController` (TRAEFIK/AWS_ALB/NGINX) only controls the *app's own* Ingress resource annotations, for a cluster where the user installed a different controller themselves outside LaraKube. **AKS has zero provisioning support** (label only, no Tofu template, no `CloudProvider::AKS` case) — out of scope, not requested.
- **The confirmed Desktop gap:** `desktop/app/Http/Controllers/ServerController.php::store()` hardcodes `'--vps'` unconditionally — Desktop cannot create a managed cluster today despite the CLI fully supporting it. `Provider` type (`desktop/resources/js/types/larakube.ts`) already carries `managedSizes`/`defaultManagedSize` from `cloud:providers --json`, just unused. **This is the lowest-effort, highest-value fix.**
- `cloud:deploy` (`app/Commands/Cloud/CloudDeployCommand.php`) is already fully generic over any kube-context: regenerates manifests from blueprint every deploy, auto-picks registry-push vs SSH-sideload from `CloudData::isManaged()`, resolves image arch per-target, runs `guardSharedStorage()` against the target's live topology, and prints DNS cutover guidance after a managed deploy. This is the most migration-ready piece in the whole system — any migration plan should call it, not reimplement it.
- `dotenv:push`/`dotenv:pull` are already fully `--context=`-parameterized.
- `plex:export` (`app/Commands/Plex/PlexExportCommand.php`) already exists specifically to "rebuild Commons on a fresh cluster" — reads live Commons config, writes JSON, pairs with `plex:init --from=`.
- `backup:run`/`backup:restore` (ADR 0010) are already cluster-agnostic by design for Commons data — `backup:restore` takes `--endpoint --bucket --access-key --secret-key --passphrase` explicitly rather than reading from the source cluster.

## Confirmed real gaps (independent of this plan, worth fixing regardless)

1. **`env <env> --context=<new>` silently no-ops** when the environment already exists (`app/Commands/EnvCommand.php:87-117` — the context-writing branch only runs for a brand-new environment; `--edit` only refreshes ingress/managed/hosts/registry, never `cloud`). Desktop's existing "Rebind {env} to a different server" UI (`projects/show.tsx`'s `LinkServerForm` → `ProjectController::link()`) calls exactly this no-op path — **Desktop's "Rebind" button likely doesn't work today** for an already-linked environment.
2. **Nothing backs up or migrates a project's own storage/self-hosted DB.** `backup:run`'s namespace scan (`InteractsWithBackup::larakubeNamespaces()`) only covers `larakube-*` namespaces (Commons/cluster-tools) — a project's own `{name}-{env}` namespace (its `laravel-storage-pvc`, SQLite data, or a self-hosted non-Commons database) is invisible to it.
3. `plex:migrate` (despite its name) only moves a project from self-hosted to Commons **within one cluster** — it is single-context throughout, not a cross-cluster tool. Its dump→quiesce→restore→resume→`plex:join` shape is the right *pattern* to imitate, not something already reusable as-is.

## Recommended phased approach

### Phase A — Expose managed cluster creation in Desktop (quick win)

- **A1 (CLI, small):** `app/Commands/Cloud/CloudProvidersCommand.php::describe()` — add `managedProvider`, `haOption`, `haCost` to the JSON it already returns (all three already exist on `ManagedProvider`, pure exposure).
- **A2 (Desktop types):** `desktop/resources/js/types/larakube.ts` — add `managedProvider?`, `haOption?`, `haCost?` to `Provider`.
- **A3 (Desktop validation):** `desktop/app/Http/Requests/StoreServerRequest.php` — add `target_kind` (`'vps'|'managed'`, **not** `kind` — that name is already used for `'server'|'dev-box'`), `node_count`, `ha`, `k8s_version_prefix`. Reject `provider === 'hetzner' && target_kind === 'managed'` server-side too (mirrors the CLI's own guard).
- **A4 (Desktop controller):** `desktop/app/Http/Controllers/ServerController.php::store()` — branch on `target_kind`: `vps` unchanged; `managed` → `--managed`, `--node-count=`, optional `--ha`, optional `--k8s-version-prefix=`. Only append `--cloudflare` on the VPS path.
- **A5 (Desktop form):** `desktop/resources/js/pages/servers/create.tsx` — VPS/Managed segmented toggle, shown only when `provider.managedSizes.length > 0` (already `false` for Hetzner — no slug special-casing needed) and only for `!devBox`. Size picker sources `provider.managedSizes`/`defaultManagedSize` when managed (extend the existing `startSize()` branch rather than duplicating it). Node count input, HA checkbox (only when `haOption === 'boolean'`, i.e. only DO today), optional advanced k8s-version field. Reuse the existing price-parsing idiom (`size?.label.match(/\(([^)]*\/mo[^)]*)\)/)?.[1]`) to show an estimated total monthly cost — directly addresses the "now has the money, should still see the number" framing.
- **Deliberately out of scope for Phase A:** no GCP zone picker (let `cloud:create` keep its `region + '-a'` default), no AKS/Azure (zero provisioning support exists anywhere).

### Phase B — Fix the rebind-no-op gap (foundational for Phase C)

- **Decision: fix `cloud:configure`, not `env`.** `cloud:configure <env>` (`app/Traits/ConfiguresCloudEnvironment.php::configureBase()`) already owns "overwrite an existing deploy target," gated behind an interactive `confirm()`. It needs one new flag, not new logic. Changing `env`'s existing-environment branch would duplicate that logic in a command whose contract is "the project-DNA wizard," not deploy-target management.
- Add `--rebind` to `app/Commands/Cloud/CloudConfigureCommand.php`. In `configureBase()`: no existing target → unchanged (first capture). Existing target + `--rebind` → skip confirm, go straight to `promptCloudTarget()`. Existing target + no `--rebind` + non-interactive + the new target actually differs → **throw** (naming `--rebind`), instead of today's silent skip-and-exit-0 — this is the project's own Strict Non-Interactive Flag Rule applied to a place that currently violates it. Existing target + no `--rebind` + the new target is identical → no-op success (idempotent, don't force the flag to re-assert the same value). Existing target + no `--rebind` + interactive → unchanged.
- Repoint `desktop/app/Http/Controllers/ProjectController.php::link()` at `cloud:configure {env} --context=... --rebind --web-hosts=...` instead of `env {env} --context=...`. Drop `--ingress=traefik`/`--managed=` from this call (inert on an existing environment; verify they're still honored by `configureBase()`'s new-environment branch for first-time links before relying on that).
- Test coverage: first-time capture (unchanged), interactive re-run with confirm (unchanged), non-interactive re-run without `--rebind` on a *different* target (now errors, was silently skipping), same target without `--rebind` (succeeds as no-op), different target with `--rebind` (now actually rebinds).

### Phase C — Orchestrate the actual migration

New command: `app/Commands/Cloud/CloudMigrateCommand.php`, `cloud:migrate {environment} {--to-context=} {--provision-managed} {--provider=} {--region=} {--size=} {--node-count=} {--ha} {--k8s-version-prefix=} {--quiesce} {--skip-dns-guidance} {--json}`. An orchestrator, not new provisioning logic:

1. Resolve source cloud target; error if nothing to migrate from or source===destination.
2. Resolve/provision destination: `--to-context=` to attach an existing cluster, or `--provision-managed` to `$this->call('cloud:create', [...forwarded flags])` and read its context back.
3. If Plex-joined: `plex:export --context=<source>` → `plex:init --from=<file> --context=<destination>` (Commons structure), then `backup:run`/`backup:restore` with explicit credentials (Commons data) — surface, never auto-run, `backup:restore`'s existing "deliberately half-manual" volume-restore instructions (ADR 0010).
4. **New work (the one real gap):** migrate the project's own storage PVC / self-hosted DB. Recommend extending `backup:run`/`backup:restore`'s existing namespace scan with an opt-in `--include-namespace=` for the project's own `{name}-{env}` namespace, rather than building and maintaining a second backup format — this is the single largest net-new engineering item in the whole plan; size it as its own sub-task.
5. `dotenv:push {environment} --context=<destination>` (already works as-is).
6. Optional `--quiesce`: scale the app to 0 replicas immediately before the final snapshot, shrinking the write-loss window (mirrors `plex:migrate`'s own quiesce step).
7. Rebind via the Phase B mechanism: `cloud:configure {environment} --context=<destination> --rebind`.
8. Redeploy: `$this->call('cloud:deploy', [$environment])` — inherits its existing registry/arch/shared-storage/DNS-guidance logic for free.
9. **Never silently promised as automatic:** DNS cutover (guidance only, no DNS-provider automation exists for this), volume restore (stays "deliberately half-manual" per ADR 0010), old-cluster teardown (never auto-destroy the source — it may be shared with other projects; end with a reminder to verify and tear down manually after a soak period).

### Closing constraints to flag to the user

- **Downtime:** this is a maintenance-window migration, not zero-downtime. `--quiesce` shrinks the loss window to "however long the snapshot+restore takes," it doesn't eliminate it. A truly zero-downtime migration needs logical replication/bidirectional sync — a much larger, separate feature.
- **Cost:** a managed cluster is categorically pricier than a $6 droplet (2+ nodes typically, control-plane fees, DOKS HA control plane is +$40/mo and irreversible once enabled). Phase A's cost-estimate line should make this concrete before commit.
- **Ingress:** recommend Traefik-on-everything for v1 — `cloud:init:gke`/`eks`/`doks` already always install Traefik regardless of provider, so a migration destination simply has what's already there; don't offer `AWS_ALB`/`NGINX` as migration-time choices (those only make sense on a cluster the user set up themselves outside LaraKube — real scope creep, deliberately deferred).

## Critical files
`app/Commands/Cloud/CloudCreateCommand.php`, `CloudConfigureCommand.php`, `CloudDeployCommand.php`, `CloudProvidersCommand.php`; `app/Traits/ConfiguresCloudEnvironment.php`, `ResolvesEnvironmentContext.php`, `InteractsWithBackup.php`; `desktop/app/Http/Controllers/ServerController.php`, `ProjectController.php`; `desktop/resources/js/pages/servers/create.tsx`; `desktop/resources/js/types/larakube.ts`.

## Verification (once built)
`cli/`: `composer format && composer analyse && composer test`, new Pest coverage per phase as described above. `desktop/`: `npx tsc --noEmit`, `php artisan test --compact`. Manual: create a managed GKE/EKS cluster from Desktop end-to-end; stand up a second test project, migrate it, confirm app + Commons data survive and DNS guidance is accurate.
