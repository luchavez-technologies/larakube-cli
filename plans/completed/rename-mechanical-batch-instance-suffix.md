# Rename the "mechanical" batch to instance-suffixed naming (loki, promtail, forgejo-runner, meet-lk-jwt)

## Context

The Stalwart/Mail rename (previous plan in this file, now shipped) established the
3-segment convention `{tool-slug}-{app-name}-{instance-slug}` for resources that
weren't touched by the original `7b06359` migration. After that shipped, the user
pasted a live `kubectl get pods -A` listing showing 13 pods still on old bare
naming. They were triaged into three groups:

- **Mechanical (this plan)**: `loki`, `promtail`, `forgejo-runner`, `meet-lk-jwt`.
  None of these has a Postgres/Commons DB dependency, so none carries Stalwart's
  "persisted, non-env-driven store config" risk that caused the production outage
  during the Mail rename. `loki` has a PVC (retained log data) but PVCs are never
  renamed anyway (established precedent), so even that risk doesn't apply here.
- **Explicitly deferred, not in this plan**: `drive-ocis` (encryption-key
  preservation risk) and `openbao-backend` (blast-radius risk) — user: "ocis is
  kinda dangerous due to that key that needs to be preserved. and openbao is
  dangerous too so be careful."
- **Flagged for a later, more careful pass**: `forgejo` (server), `grafana`,
  `link-kutt`, `sign-documenso`, `sso-zitadel`, `vaultwarden` — all have a real
  Commons DB dependency and need the same "does this tool persist its own
  connection identity outside env vars" check Stalwart's incident taught us to do
  first, before touching Postgres.

Investigation for this batch turned up two things worth calling out up front:

1. **None of these four pods is actually wired through `ClusterTool::components()`
   today**, despite appearances. `GitForgeTool::components()` already declares a
   `forgejo-runner` WORKER component with a correct `$name()`-suffixing closure —
   but `GitInitCommand` never calls `components()` to render its manifest; it
   hardcodes `deploy/forgejo-runner` literally throughout. `loki`/`promtail` aren't
   modeled as `ClusterTool` components at all — Monitor's vendor only declares
   `grafana` (`HasDeploymentBaseName`), and the rest of the stack is deployed by a
   completely separate, hand-rolled path (`monitoring/shared.blade.php` +
   `MonitorInitCommand`). `meet-lk-jwt` isn't part of `MeetTool::components()`
   either (Meet only declares `meet-livekit`) — the bridge is deployed by
   `meet:wire`/`meet:unwire`, a distinct command pair from `meet:init`.
2. **A real, pre-existing bug**: `MeetWireCommand::reapplyMeet()` and the reload
   step in `MeetUnwireCommand` re-render `k8s.meet.livekit` and `k8s.meet.ingress`
   WITHOUT passing `'instance'`. Since Meet was already migrated to instance-suffixed
   naming in `7b06359`, every `meet:wire`/`meet:unwire` call since then has been
   re-rendering those two manifests with the suffix silently dropped — i.e.
   generating a bare-named `meet-livekit-config`/`meet-livekit` Secret/Service next
   to the real suffixed Deployment, which never picks it up. This needs fixing as
   part of this pass regardless — otherwise the new `meet-lk-jwt-{instance}` naming
   added here would get silently reverted to bare on the very next `meet:wire` call.

**Accepted, deliberate inconsistency**: this pass renames `forgejo-runner` while
`forgejo` (server) stays bare, and renames `loki`+`promtail` while `grafana`/
`prometheus`/`tempo`/`kube-state-metrics` stay bare. Both are safe independently
(no shared state, no naming coupling beyond what's called out below) and match how
every prior step of this migration has proceeded — tool-by-tool, resource-by-
resource, converging over several passes rather than one atomic sweep.

## Naming decisions

| Resource | Current (bare) | New | PVC/data? |
|---|---|---|---|
| GIT runner Deployment | `forgejo-runner` | `git-forgejo-runner{-instance}` | none (emptyDir only) |
| GIT runner ConfigMap | `forgejo-runner-config` | `git-forgejo-runner-config{-instance}` | — |
| MEET bridge Deployment | `meet-lk-jwt` | `meet-lk-jwt{-instance}` | none |
| MEET bridge Service | `meet-lk-jwt` | `meet-lk-jwt{-instance}` | none |
| MEET bridge pod label | `app: meet-lk-jwt` | **unchanged** (stable label — matches the Mail precedent; `SharedClusterService::MEET`'s presence probe has no `$instance` in scope) | — |
| MEET bridge Middleware | `meet-jwt-stripprefix` | **unchanged** | — |
| MONITOR Loki Deployment/Service | `loki` | `monitor-loki-{instance}` | — |
| MONITOR Loki ConfigMap | `loki-config` | `monitor-loki-config-{instance}` | — |
| MONITOR Loki PVC | `loki-storage` | **unchanged** — holds up to 7 days of retained logs | **DATA** |
| MONITOR Promtail DaemonSet | `promtail` | `monitor-promtail-{instance}` | — |
| MONITOR Promtail ConfigMap | `promtail-config` | `monitor-promtail-config-{instance}` | — |
| MONITOR Promtail ServiceAccount / ClusterRole / ClusterRoleBinding | `promtail` / `larakube-promtail` | **unchanged** — not visible via `kubectl get pods`, MONITOR is a singleton so there's no collision to solve, and ClusterRole/Binding are cluster-scoped (higher blast radius to touch for zero benefit) | — |

**Critical coupling to update, not just a name change**: `promtail-config`'s
`clients[].url` is hardcoded to
`http://loki.larakube-shared.svc.cluster.local:3100/loki/api/v1/push`. If this
isn't updated to the new Loki Service name in the same apply, Promtail keeps
running "successfully" (its own health checks don't depend on Loki) while
silently dropping every log line — exactly the class of silent-failure bug
Stalwart's config-drift incident was about, just in a different tool.

## Code changes

### GIT (`forgejo-runner`)

- `app/Enums/GitForgeTool.php` `components()`: change the runner component's
  `deployment: $name('forgejo-runner')` → `$name('git-forgejo-runner')`. Server
  component (`forgejo`) stays as-is.
- `app/Commands/Git/GitInitCommand.php`: right after resolving `$host` (line
  ~124), compute `$instance = ClusterTool::GIT->instanceSlugFromHost($host)` —
  matching the exact comment/pattern already used in `MeetInitCommand.php`. Thread
  `'instance' => $instance` into both `k8s.git.forgejo` renders (initial + final
  pass). Replace the hardcoded `deploy/forgejo-runner` in the "Waiting for Actions
  Runner" rollout-status check with
  `ClusterTool::GIT->componentByKey('runner', $instance)->deployment`.
  `registerDeployedTool(ClusterTool::GIT, $kubectl, $host, extra: [...])` needs NO
  change — `registerDeployedTool()`'s `$instance` param already auto-derives from
  `$host` when omitted (`DeploysClusterTool.php:157`), so GIT's registry entry is
  already correct today.
- `resources/views/k8s/git/forgejo.blade.php`: accept `$instance`, compute
  `$suffix` the same way every other migrated Blade template does. Apply it to the
  runner ConfigMap's `metadata.name` and the Deployment's `metadata.name`/
  `spec.selector.matchLabels.app`/`template.metadata.labels.app` (label matches
  name exactly — no stable-label need here, GIT already resolves its instance via
  the registry before any command targets a specific deployment). Update the
  Deployment's own `volumes[].configMap.name` reference to match the renamed
  ConfigMap. `forgejo` server block, its Service names, and `git-secrets` stay
  bare — out of scope.
- `app/Commands/Git/GitRemoveCommand.php`: **no change** — its `teardown()`
  already calls `teardownComponentsCommand($kubectl, $namespace,
  $this->resolveInstance($kubectl))`, which generically walks
  `ClusterTool::GIT->components($instance)`. Once the enum change above lands,
  removal picks up the new name automatically.

### MEET (`meet-lk-jwt` + the reapply bug)

- `resources/views/k8s/meet/lk-jwt.blade.php`: accept `$instance`, compute
  `$suffix`. Deployment/Service `metadata.name` → `meet-lk-jwt{$suffix}`. Pod
  labels (`app: meet-lk-jwt`) stay unsuffixed (stable, matches the table above).
  Middleware name stays `meet-jwt-stripprefix`.
- `resources/views/k8s/meet/ingress.blade.php` line 46: `name: meet-lk-jwt` →
  `name: meet-lk-jwt{{ $suffix }}` — reuses the `$suffix` already computed at the
  top of this file from `$instance`.
- `app/Commands/Meet/MeetWireCommand.php`: in `deployMeet()`'s bridge-deploy step,
  compute `$instance = ClusterTool::MEET->instanceSlugFromHost($meetHost)` and
  pass `'instance' => $instance` into the `k8s.meet.lk-jwt` render. **Fix the
  pre-existing bug**: pass the same `'instance' => $instance` into BOTH
  `k8s.meet.livekit` and `k8s.meet.ingress` inside `reapplyMeet()` — currently
  neither gets it, silently re-rendering those two as bare on every wire/unwire.
- `app/Commands/Meet/MeetUnwireCommand.php`: same fix in the reload block (lines
  ~86-97) — compute `$instance` from `$meetHost` and pass it to both views. Also
  update the bridge-presence check (`get deployment meet-lk-jwt`) and the delete
  step (`delete deployment/meet-lk-jwt service/meet-lk-jwt ...`) to target by
  label (`-l app=meet-lk-jwt`) instead of exact name, so unwire finds the bridge
  regardless of instance suffix.
- `app/Commands/Meet/MeetInitCommand.php` `isMeetChatWired()` (line 142): switch
  `get deployment meet-lk-jwt` to `get deployment -l app=meet-lk-jwt` for the same
  reason.
- `app/Commands/Meet/MeetRemoveCommand.php`: update the `meet-lk-jwt`/
  `meet-lk-jwt` refs in the delete command to target by label instead of exact
  name (the comment explaining why the bridge is torn down alongside the SFU
  stays accurate and doesn't need rewording).
- `app/Enums/SharedClusterService.php` MEET's `jwtWired` probe (line 82): switch
  `get deployment meet-lk-jwt` to `get deployment -l app=meet-lk-jwt`.

### MONITOR (`loki`, `promtail`)

- `app/Commands/Monitor/MonitorInitCommand.php`: right after resolving `$host`
  (line ~64), compute `$instance = ClusterTool::MONITOR->instanceSlugFromHost($host)`.
  Thread `'instance' => $instance` into the `k8s.monitoring.shared` render.
  Update: `reconcileMonitoringComponents()`'s `lokiPresent` check (currently `get
  deployment/loki`) to the new name (pass `$instance` in as a new param); the
  `--no-logs` removal step's literal delete lists (both `loki`/`loki-config` and
  `promtail`/`promtail-config`, keeping `loki-storage` bare/untouched in the
  delete list too — same PVC caveat as everywhere else); the `Waiting for
  Loki`/`Waiting for Promtail` rollout-status checks; the final `Loki:
  loki.{$ns}...` display line.
- `resources/views/k8s/monitoring/shared.blade.php`: accept `$instance`, compute
  `$suffix`. Loki's Deployment/Service `metadata.name` and ConfigMap
  `loki-config`'s name get the suffix (plus the Deployment's
  `volumes[].configMap.name` reference); `loki-storage` PVC name and its
  `volumes[].persistentVolumeClaim.claimName` reference stay bare. Promtail's
  DaemonSet name and `promtail-config` ConfigMap name get the suffix (plus the
  `volumes[].configMap.name` reference); ServiceAccount/ClusterRole/
  ClusterRoleBinding stay bare (table above). **`promtail-config`'s `clients[].url`
  must be rewritten from the hardcoded `loki.larakube-shared.svc.cluster.local`
  to the new suffixed Loki Service name** — this is the one place a missed update
  fails silently instead of loudly.
- `app/Commands/Monitor/MonitorRemoveCommand.php`: `teardown()`'s `$steps` array
  doesn't go through `components()` (Loki/Promtail aren't modeled there) — resolve
  `$instance = $this->resolveInstance($kubectl)` directly (same pattern
  `GitRemoveCommand`/`MeetRemoveCommand` already use) and build the suffix inline
  for the `'Removing Loki...'`/`'Removing Promtail...'` steps' target lists. The
  RBAC removal step and every other step stay unchanged.
- Display-string call sites that hardcode `loki.{$ns}.svc.cluster.local` for
  `monitor:show`/`about` output: `app/Vendors/MonitorTool.php` (`toolAccessRows()`,
  already receives `string $instance = ''` — same pattern as `GitForgeTool`) and
  `app/Traits/InteractsWithMonitoring.php`'s `monitoringAccess()` (needs to resolve
  MONITOR's registered instance the same way `resolveGrafanaHostReadOnly()`
  resolves its host, then build the Loki DNS name from it).

## Live migration sequence (prod)

Much lower-risk than the Mail rename — no Postgres/OpenBao steps, no scale-to-zero
window, no chicken-and-egg persisted-config problem. Every write step is still run
by the user directly (live-prod writes are consistently blocked for me by the
Claude Code auto-mode classifier); I verify read-only after each.

1. Rebuild (`./php vendor/bin/pint && ./build`).
2. `git:init <env>` (idempotent) — creates the new `git-forgejo-runner-{instance}`
   Deployment/ConfigMap alongside the still-running old bare ones (different
   `metadata.name`, not an in-place replace). Verify the new runner pod is
   `1/1 Running` and picks up Actions jobs (labels are read from the ConfigMap,
   registration reads the same `runner-secret`/`git-secrets`, so the new pod
   should self-register identically). Then delete the old leftovers:
   `kubectl delete deployment/forgejo-runner configmap/forgejo-runner-config -n larakube-shared --ignore-not-found`.
3. `meet:wire <env> --tool=chat` (idempotent) — re-applies LiveKit + ingress
   (now correctly instance-suffixed, fixing the reapply bug) and deploys the new
   `meet-lk-jwt-{instance}` bridge. Verify: LiveKit pod didn't lose its running
   config (check `meet-livekit-config-{instance}` Secret still has the full
   consumer registry, not just chat's key), bridge pod `1/1 Running`, a real test
   call in Element still connects (Focus URL 200s). Then delete old bare
   leftovers: `kubectl delete deployment/meet-lk-jwt service/meet-lk-jwt -n larakube-shared --ignore-not-found`
   (and, only if `reapplyMeet()`'s bug already left stray bare `meet-livekit`/
   `meet-livekit-config` resources behind from a past `meet:wire` run — check for
   these explicitly before deleting anything, since a live investigation hasn't
   confirmed yet whether they actually exist).
4. `monitor:init <env>` (idempotent) — creates `monitor-loki-{instance}` and
   `monitor-promtail-{instance}`. Verify both pods `1/1`/`Running` on every node,
   AND verify logs are actually flowing end-to-end (Grafana Explore → Loki
   datasource → a live log line from a recent pod restart) before touching
   anything old — this is the step where a missed `promtail-config` URL update
   would fail silently. Then delete old bare leftovers:
   `kubectl delete deployment/loki service/loki configmap/loki-config daemonset/promtail configmap/promtail-config -n larakube-shared --ignore-not-found`
   (leaving `loki-storage` PVC and the ServiceAccount/ClusterRole/ClusterRoleBinding
   untouched, per the table above).
5. Update `plans/active/openbao-static-role-coverage.md` or wherever the
   naming-migration status is tracked, and the relevant memory file, to record
   this batch as done and the remaining group (`forgejo`, `grafana`, `link-kutt`,
   `sign-documenso`, `sso-zitadel`, `vaultwarden`, plus `drive-ocis`/
   `openbao-backend`) as still pending.

## Verification

- After each code change: `./vendor/bin/pint && php -d memory_limit=1G
  ./vendor/bin/phpstan && ./vendor/bin/pest --parallel`, matching the pre-commit
  hook.
- Test files needing updates for the new names: `tests/Feature/Git*Test.php`,
  `tests/Feature/Meet*Test.php`, `tests/Feature/Monitor*Test.php`,
  `tests/Unit/ClusterToolComponentsTest.php` (GIT runner's expected deployment
  name) — same style of hardcoded-name assertion sweep as the Mail rename, just a
  much smaller surface (no DB/OpenBao mocking involved for any of these four).
- Live, per migration step above: read-only confirmation after every user-run
  write — pod health, and for Monitor specifically, actual log delivery through
  the renamed pipeline (not just "pod is Running"), since that's the one failure
  mode here that doesn't show up as a crash.
