# 0026 — A tool install is checked against live cluster capacity, derived from its own manifest

**Status:** Accepted (2026-10-10)

## Context

Nothing checked whether a cluster had room for a tool before installing it. `CloudDiagnoseCommand`
is purely reactive — it reads `.status.conditions` for pressure, OOMKilled terminations, and
Evicted events, all *after* a tool has already crashed. By the time an operator gets signal, the
server is already in a bad state, often with other tools' pods evicted collaterally.

Resource declarations in tool manifests were also ad hoc rather than audited. Forgejo's main
server container, the entire 6-component monitoring stack (Prometheus/Loki/Tempo/Promtail/
kube-state-metrics/Grafana), and n8n declared no `resources:` block at all — exactly the shapes
the user cited as the motivating examples ("how Forgejo and Grafana stack has resource-intensive
components").

Two designs were considered for where a tool's sizing comes from:

1. **A second, hand-authored sizing field** on `ClusterToolComponentData` (the existing
   per-component registry used for teardown/backup discovery).
2. **Derive it from the manifest itself**, parsing the already-rendered `resources:` blocks.

## Decision

**1. Sizing is derived from the rendered manifest, never hand-authored a second time.**
`ClusterToolComponentData` already has one field (`resources`) whose name collides with real
Kubernetes resource limits while meaning something unrelated (teardown targets). A second,
differently-meaning sizing field would recreate exactly the "second source of truth that drifts
from the template" problem that field's own docblock already warns about. `ManifestResourceParser`
reads `resources.requests`/`limits` straight out of the rendered Blade output instead — the
template stays the single source of truth, and a number nobody remembers to update in two places
can't drift, because there is only one place.

**2. Only `Deployment`/`DaemonSet` are considered; `initContainers` are ignored.** An init
container runs to completion before the steady-state containers start, so it never competes with
them for a node's capacity concurrently — including it would overstate demand for no real benefit.

**3. An undeclared container counts at a conservative floor, never zero.** A manifest nobody has
sized yet (the pre-Phase-0 state of Forgejo/monitoring/n8n) must not look like it needs nothing —
that would make the guard actively misleading for exactly the tools most likely to need it.

**4. A DaemonSet's replica count is the live node count, not a manifest field.** `DaemonSet` has no
`replicas:` in its spec — it runs once per node, a number only the live cluster knows. The parser
returns `replicas: 1` for a DaemonSet and documents that the caller must multiply by the node count
itself (Promtail is the concrete case this was designed against).

**5. The guard reads live state fresh each time** (`kubectl get nodes -o json` for allocatable,
`kubectl get pods -A -o json` summed over `Running`/`Pending` phases for already-requested), rather
than caching or estimating. A 10% safety margin is reserved off allocatable before comparing, to
account for node pressure and per-node overhead (kube-proxy, CNI) that doesn't always show up as a
regular pod request.

**6. Either live read failing steps the guard aside, not blocks the install.** If `kubectl get
nodes` or `kubectl get pods -A` fails, the snapshot is `null` and the guard returns `true`
(unconditional pass) rather than refusing. The guard's own inability to read the cluster is not
the user's problem to debug through a failed tool install — a real connectivity problem will
surface naturally at the `kubectl apply` step that follows.

**7. Fits → silent. Doesn't fit → warn, then `confirm()`; `--force` bypasses; non-interactive
without `--force` refuses.** This mirrors the existing `RequiresFlagsWhenNonInteractive` convention
used everywhere else in the CLI for a go/no-go that must not be silently guessed in headless mode
(CI, the `larakube` proxy, MCP tool calls). A plain `confirm()` is used rather than
`ConfirmsDestructiveAction::confirmDestructive()`'s typed-`confirm` pattern — this is a risk
go/no-go on a reversible action (a failed/undersized install can be removed), not a destructive one.

**8. Wiring point is `VerifiesKubernetesRollout::applyAndVerifyRollout()`**, extended with an
optional `?ClusterTool $tool` parameter — confirmed via direct grep to be the single highest-leverage
call site, used immediately before `kubectl apply` by the majority of concrete `*InitCommand`
classes. Left `null` for the cluster-provisioning Traefik installers (DOKS/EKS/GKE), which apply a
manifest before any tool exists to check demand against.

## Consequences

- **27 of ~35 tool installs are guarded** as of this ADR: the 24 standard single-apply
  `*InitCommand` classes through the extended trait method, plus `FlowInitCommand` (n8n/Windmill),
  `MonitorInitCommand` (the Prometheus/Loki/Grafana stack), and `ChatInitCommand` (Matrix's main
  Synapse+Element bundle only) wired by hand ahead of their custom multi-step applies.
- **Six installs remain unguarded, by documented decision, not oversight** — tracked in
  `plans/active/pre-install-cluster-capacity-guard.md` and
  `memory/project_capacity_guard_phase2_deferred.md`: Plex Commons (`plex:init`, the most complex
  multi-service install), ExternalDNS, Collabora/CODE, GlitchTip, Infisical Secrets (each applies
  outside `applyAndVerifyRollout()` via a bespoke path never individually judged), and NetBird's own
  heavy management/signal/relay bundle (only its lightweight per-client sidecar got the standard
  wiring).
- **Three installs are exempt by design**: `backup:init` (a CronJob, not a standing workload),
  `snapshot:init` (CRDs + a CSI controller, cluster plumbing rather than a user-picked tool), and
  `tls:init` (patches an existing Traefik config, creates no new workload).
- **Only three tool manifests needed a Phase 0 retrofit** (Forgejo, the monitoring stack, n8n) —
  every other Cluster Tool manifest already declared `resources:`, confirmed before this guard was
  designed rather than assumed.
- **No `--json` output exists yet.** No `*InitCommand` has a `--json` flag today, and designing one
  speculatively (without a real Desktop consumer to design against) was explicitly deferred rather
  than bolted on. `--capacity-margin=` (an override for the 10% default) was considered and also
  deferred for the same reason — no concrete need surfaced one yet.
- **The interactive-confirm-accepted/declined branch has no dedicated unit test.** `cannotPrompt()`
  treats `app()->runningUnitTests()` as always non-interactive, so only the `--force` path and the
  non-interactive-refusal path are reachable from a test — the same limitation `SsoPruneCommandTest`
  already lives with for an unrelated confirm prompt. Coverage instead proves the actual wiring
  end-to-end (a tiny-cluster fixture against real Forgejo/n8n manifests refuses without `--force`
  and proceeds with it) rather than the interactive-prompt UX itself.
