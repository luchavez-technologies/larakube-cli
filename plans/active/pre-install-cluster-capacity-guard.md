# Pre-install cluster capacity guard (don't OOM a server installing a tool)

## Status: Phases 0-2 SHIPPED (2026-10-10). Phase 3 not started.

## Context

The user wants the CLI to answer "will installing this tool OOM the server?" *before* applying its manifest — with real per-component granularity (Forgejo's lightweight server vs. its separate Actions-runner component; the monitoring stack's lightweight Promtail vs. heavy Prometheus/Loki), not one flat number per tool.

Starting state: no node-capacity checking existed anywhere. `CloudDiagnoseCommand` was purely reactive (checks for OOMKilled pods/MemoryPressure *after* the fact). Resource declarations in tool manifests were ad hoc — Forgejo's main server container, the entire 6-component monitoring stack, and n8n declared **none**, exactly the tools the user cited as examples.

## Recommended approach: derive from the manifest, don't hand-author a second number

Parse the already-rendered manifest for `resources.requests`/`limits`, per container, summed per component via `ClusterTool::components()`. The manifest Blade template stays the single source of truth — no second hand-maintained sizing field on `ClusterToolComponentData` (which already has one confusingly-named `resources` field for teardown targets).

## Phase 0 — SHIPPED (commits `4b13da6f`, `6cf5f32c`)

- Retrofitted real `resources:` blocks onto Forgejo's server container, the monitoring stack's 6 containers (prometheus/loki/tempo/promtail/kube-state-metrics/grafana), and n8n's container.
- n8n's numbers are grounded in a live `kubectl top pod` reading off `luchtech-vps` (`larakube-159.89.205.239`): ~452Mi observed memory at idle vs n8n's own published 250Mi/500Mi example manifests — requests set to 512Mi/1Gi limit accordingly, not guessed.
- `app/Services/K8s/ManifestResourceParser.php` + `app/Data/{WorkloadResourceProfile,ContainerResourceProfile}.php`: parses a rendered manifest string (Deployment/DaemonSet only, initContainers ignored) into per-container resource profiles. 7 unit tests (`tests/Unit/ManifestResourceParserTest.php`) against the 3 retrofitted manifests plus synthetic edge cases.

## Phase 1 — SHIPPED (commit `2c2460c9`)

- `app/Data/ClusterCapacitySnapshot.php`: node count, allocatable/requested CPU+memory, 10% safety margin by default, `fits()`/`freeCpuMillicores()`/`freeMemoryBytes()`.
- `app/Traits/InteractsWithClusterCapacity.php`: `clusterCapacitySnapshot()` (reads `kubectl get nodes -o json` + `kubectl get pods -A -o json`, sums Running/Pending pod requests only) and `manifestResourceDemand()` (sums a manifest's workloads, DaemonSet × live node count, undeclared containers count at a floor instead of zero).
- `guardClusterCapacity()` on `AbstractToolInitCommand`: fits → silent pass; doesn't fit → warns + `confirm()`; `--force` bypasses; non-interactive without `--force` refuses (same convention as `RequiresFlagsWhenNonInteractive`); either live read failing steps the guard aside rather than blocking an install over the tooling's own gap.
- 17 unit tests across `tests/Unit/{InteractsWithClusterCapacityTest,ClusterCapacityGuardTest}.php`. Note: the interactive-confirm-accepted/declined branch is NOT unit-testable in this harness — `cannotPrompt()` treats `app()->runningUnitTests()` as always non-interactive, same limitation `SsoPruneCommandTest` already lives with (only `--force` and the refusal path are reachable from a test).

## Phase 2 — SHIPPED (commit `b73a1da3`)

- `VerifiesKubernetesRollout::applyAndVerifyRollout()` gained an optional `?ClusterTool $tool` param; when given, runs `guardClusterCapacity()` against the manifest before applying. Left `null` for the 3 cloud-provisioning Traefik installers (DOKS/EKS/GKE) — no tool exists yet to check against.
- Wired into the 24 standard single-apply `*InitCommand` classes: Analytics, Crm, Dashboard, Data, Design, Drive, Git, Insights, Link, Mail, Meet, Notes, Paste, Passwords, Record, Resume, Sheets, Sign, Sso, Tasks, Support, Uptime, Vpn (client sidecar pod only), Webmail.
- Manually wired into 3 custom multi-step installs ahead of their main apply: `FlowInitCommand` (n8n/Windmill), `MonitorInitCommand` (Prometheus/Loki/Grafana stack), `ChatInitCommand` (Matrix's main Synapse+Element bundle only, not its lighter MAS/Element-Admin follow-up applies).
- New end-to-end tests proving the wiring is real (not just "doesn't break existing tests"): `GitInitCommandTest.php` and `FlowInitCommandTest.php` each gained a "refuses on a tiny cluster without --force" + "--force proceeds anyway" pair.
- Every pre-existing `*InitCommandTest.php` stayed green untouched — an unreadable cluster state (the overwhelming majority of existing fixtures, which don't fake node/pod reads) makes the guard step aside silently. Only `VpnInitCommandTest.php`'s one `preventStrayProcesses()` test needed new fakes.

### Deliberately NOT wired in Phase 2 — a documented gap, not an oversight

- **Plex Commons** (`plex:init` — Postgres/Redis/SeaweedFS): the most complex multi-service install (per-service prompts, conditional deploys), resource-heavy, deserves its own focused pass rather than being bundled into an already-large Phase 2.
- **ExternalDNS** (`DnsInitCommand`), **Collabora/CODE** (`OfficeInitCommand`), **GlitchTip** (`ErrorsInitCommand`), **Infisical** (`SecretsInitCommand`): each applies its manifest through a bespoke path outside `applyAndVerifyRollout()` (confirmed via grep — they only appear in the function as a comment, not a real call), never individually read/judged for whether the guard is warranted.
- **NetBird's own heavy management/signal/relay bundle** (`VpnInitCommand`): only its lightweight per-client sidecar pod got the standard wiring; the real server bundle applies via a custom 3-resource verify step, untouched.

### Judged exempt by design (confirmed via a real read, not assumed)

- `BackupInitCommand` — configures an off-site destination + a CronJob, not a standing Deployment.
- `SnapshotInitCommand` — installs VolumeSnapshot CRDs + CSI snapshot controller, cluster plumbing rather than a user-picked Cluster Tool.
- `TlsInitCommand` — switches an existing cluster's ACME challenge type (patches Traefik config), creates no new workload.

## Phase 3 — NOT STARTED

- Finalize a `--json` payload shape for the guard (no `*InitCommand` has a `--json` flag today; this needs its own design pass against Desktop's actual needs rather than being bolted on speculatively).
- Consider a `--capacity-margin=` override flag.
- Write ADR 0026 documenting the derive-vs-static decision, the `--force`/non-interactive semantics, and the DaemonSet×node-count multiplier rule.
- Resolve the Phase 2 deferred list above: read each bespoke-apply command fresh (don't assume its shape is still what's described here) and either wire it or make an explicit, reasoned exemption call.

## Verification

`composer format && composer analyse && composer test` green at every phase (non-negotiable). Manual verification (spin up a deliberately small local cluster, confirm the guard actually warns before a real OOM) has NOT been done yet — only unit/feature test coverage exists so far.
