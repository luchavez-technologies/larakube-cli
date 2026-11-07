# Pause / Resume for Installed Cluster Tools

## Context

The Desktop app's Apps page shows installed Cluster Tools (WordPress, PocketBase, n8n, etc.) with Visit/Update/Manage actions, but no way to free up cluster RAM for a tool the user isn't actively using without losing its data. Projects already have an analogous "Stop" (scale replicas to 0) / "Start" (scale back to 1) pair, but `StopCommand`/`StartCommand` blindly use `--all --replicas=0` / `--all --replicas=1` — they don't remember each deployment's real prior replica count.

A better building block already exists and is unused for this purpose: `cli/app/Traits/QuiescesAppDeployments.php` captures each deployment's actual `spec.replicas` before zeroing it, and can restore that exact count later. Today it's only used internally during Plex/Cloud migrations. We're generalizing that idea into a user-facing Pause/Resume action for any installed Cluster Tool, scoped to just that tool's own deployment(s) (via `ToolInstance->components()`), never touching PVCs, secrets, the namespace, or the tool registry.

Decisions already confirmed with the user:
- **UI**: Pause/Resume button lives directly on the Apps card (next to Update/Manage), with the card badge switching to a "Paused" state.
- **State storage**: the pre-pause replica count is stored as a Kubernetes annotation on each Deployment (e.g. `larakube.app/paused-replicas: "3"`), not in Desktop's database — keeps the cluster as source of truth and keeps the CLI usable standalone.

## CLI changes (`cli/`)

### 1. `App\Services\Kubectl` — add a `scale()` helper
File: `cli/app/Services/Kubectl.php`, alongside `rolloutStatus()` (line 423) / `rolloutRestart()` (line 428) — same thin-wrapper pattern:
```php
public function scale(string $namespace, string $deployment, int $replicas): bool
{
    return $this->run(['scale', "deployment/{$deployment}", '-n', $namespace, "--replicas={$replicas}"]);
}
```
No existing method reads live replica counts from this service class (`AbstractToolShowCommand::componentSummary()` does its own `kubectl get deployment -o json` parse) — reuse that same raw-JSON approach rather than adding a redundant Kubectl method, since annotation + scale calls are all the new commands need directly.

### 2. New trait: `App\Traits\TogglesToolPause`
Mirrors `TogglesToolProxy` (used by `ToolProxyCommand`/`ToolUnproxyCommand`) and borrows the capture/restore logic shape from `QuiescesAppDeployments`, but scoped to one `ToolInstance`'s components instead of "every deployment in the namespace".

**Multi-component ordering**: a tool like Matrix (7 components across all 5 `ClusterToolComponentRole` values — `PRIMARY` synapse, `AUTH` mas with its own `DATABASE`, synapse's own `DATABASE`, `INGRESS` element-web, `WORKER` coturn/admin) has real dependencies between roles. Components are paused dependents-first (`INGRESS`/`WORKER` → `PRIMARY` → `AUTH` → `DATABASE` last) and resumed in reverse (`DATABASE`/`AUTH` first, `PRIMARY` next, `INGRESS`/`WORKER` last) so a resumed Synapse/MAS doesn't crash-loop against a still-cold bundled DB. This is role-based, not tool-specific, so it's free for all 33 tools without per-tool code.

- `pauseTool(ClusterTool $tool, string $instance, string $kubectl, string $namespace): int`
  1. Resolve `$names = ToolInstance::forInstance($tool, $instance)`.
  2. For each `$names->components()` entry, read the deployment's current `spec.replicas` via `kubectl get deployment/{name} -n {ns} -o jsonpath='{.spec.replicas}'`.
  3. **Idempotency**: skip (no-op, not an error) any deployment already at 0 replicas or already carrying the `larakube.app/paused-replicas` annotation.
  4. `kubectl annotate deployment/{name} -n {ns} larakube.app/paused-replicas={count} --overwrite` **before** scaling down.
  5. `Kubectl::scale($namespace, $name, 0)`.
  6. Report via `LaraKubeOutput` which components were paused vs. already paused.
- `resumeTool(ClusterTool $tool, string $instance, string $kubectl, string $namespace): int`
  1. Same resolve step.
  2. For each component: read the `larakube.app/paused-replicas` annotation (`kubectl get deployment/{name} -o jsonpath='{.metadata.annotations.larakube\.app/paused-replicas}'`); default to `1` if missing (idempotent-safe if resume is called on something not paused by this feature).
  3. **Idempotency**: skip any deployment already above 0 replicas.
  4. `Kubectl::scale($namespace, $name, $count)`.
  5. `kubectl annotate deployment/{name} -n {ns} larakube.app/paused-replicas-`  (remove the annotation) once scaled back up.

### 3. New commands
File pattern matches `ToolProxyCommand`/`ToolUnproxyCommand` (thin signature, trait does the work) — generic across all 33 tools, no per-tool subclassing needed:

- `cli/app/Commands/Tool/ToolPauseCommand.php` — signature `tool:pause {environment} {--domain=} {--context=}`, description *"Scale a Cluster Tool's pods to zero to free RAM — data (PVCs, secrets) is preserved"*, `handle()` calls `$this->pauseTool(...)`.
- `cli/app/Commands/Tool/ToolResumeCommand.php` — signature `tool:resume {environment} {--domain=} {--context=}`, description *"Scale a paused Cluster Tool's pods back up to its prior replica count"*, `handle()` calls `$this->resumeTool(...)`.

Resolve `$tool`/`$instance` the same way `ToolProxyCommand`'s `TogglesToolProxy` trait and `AbstractToolShowCommand` do, via `ResolvesToolHost` + `--domain=`.

### 4. Surface paused state in `{tool}:show`
Extend `AbstractToolShowCommand::componentSummary()` (`cli/app/Commands/Tool/AbstractToolShowCommand.php:256-320`) — when building each component's `$summary` array, also read the `larakube.app/paused-replicas` annotation (already have the raw JSON from the `kubectl get deployment -o json` call at the top of the method, so pull `metadata.annotations` per item instead of a second kubectl call) and add `'paused' => isset($item['metadata']['annotations']['larakube.app/paused-replicas'])`. This is what the Desktop app reads to decide which button/badge to show.

### 5. Tests
- New `tests/Feature/ToolPauseCommandTest.php` / `ToolResumeCommandTest.php` — fake `Process` responses for `get deployment ... -o jsonpath=...`, `annotate`, and `scale`; assert idempotent no-op when already paused/resumed; assert the exact prior replica count round-trips.
- Extend the `{tool}:show` test(s) covering `componentSummary()` to assert the new `paused` key.

## Desktop changes (`desktop/`)

### 1. `RunKind` enum
File: `desktop/app/Enums/RunKind.php` — add, next to `PlexStart`/`PlexStop` (line 74-75) or `WorkspaceSuspend`/`WorkspaceResume` (line 95-96):
```php
case PauseClusterTool = 'pause-cluster-tool';
case ResumeClusterTool = 'resume-cluster-tool';
```

### 2. Routes
File: `desktop/routes/web.php` (near the existing cluster-tool routes, `ClusterToolController` block ~259-286) — add:
```php
Route::post('tools/{tool}/pause', [ClusterToolController::class, 'pause'])->name('tools.pause');
Route::post('tools/{tool}/resume', [ClusterToolController::class, 'resume'])->name('tools.resume');
```

### 3. Controller actions
File: `desktop/app/Http/Controllers/ClusterToolController.php` — add `pause()`/`resume()` following the exact `wire()` shape (`ClusterToolController.php:350-424`): validate request → resolve server/context → build `['tool:pause', $environment, "--context={$context}", "--domain={$domain}"]` (or `tool:resume`) → map to `RunKind::PauseClusterTool`/`ResumeClusterTool` → `CliRunner::start(...)` → redirect to `runs.show`.

### 4. UI
File: `desktop/resources/js/pages/tools/index.tsx`, `InstalledCard` (~line 1285, where Update/Manage render) — add a Pause/Resume button that toggles based on a new `paused` flag surfaced through `App\Services\LaraKube\ToolCatalog` (reads `tool:show --json`, which per CLI change #4 now includes `paused` per component — treat the tool as paused when *all* its components are paused). Button and route follow the Update button's existing wiring pattern once un-stubbed (currently `disabled` with a "Coming soon" tooltip — Pause/Resume is a new, separately-wired action, not reusing that disabled Update button).
Card badge: swap `● Installed` for a `⏸ Paused` state (same visual slot used for status, e.g. near where `WordPress ... ● Installed` renders in the card header) when paused.

### 5. Tests
- `desktop/tests/Feature/ClusterToolsTest.php` — add cases asserting the pause/resume routes build the correct CLI args and `RunKind`.

## Verification

1. `cd cli && composer test` — new `ToolPauseCommandTest`/`ToolResumeCommandTest` pass; full suite still green.
2. `cd cli && composer analyse` — PHPStan clean.
3. Manual, against the already-running `blogs.larakube.app` WordPress install (`larakube-35.201.152.19` context) as a live test subject:
   - `larakube tool:pause production --domain=blogs.larakube.app` → `kubectl get deploy wordpress-blogs-larakube-app -n larakube-shared` shows `0/0` replicas; `kubectl get deploy ... -o jsonpath='{.metadata.annotations}'` shows `larakube.app/paused-replicas=1`. PVC and Secret for the tool are untouched (`kubectl get pvc,secret -n larakube-shared | grep wordpress`).
   - `larakube tool:show production --domain=blogs.larakube.app --json` reports `paused: true` for the component.
   - `larakube tool:resume production --domain=blogs.larakube.app` → deployment back to `1/1`, annotation removed, site reachable again at `https://blogs.larakube.app/`.
   - Re-running `tool:pause` twice in a row, and `tool:resume` on an already-running tool, are both no-ops (no error, no duplicate annotation writes).
4. `cd desktop && composer test` (`ClusterToolsTest`) and `npm run check` (formatting/lint) green.
5. In Desktop, pause the installed WordPress card, confirm the badge flips to Paused and the Visit URL returns a connection error while paused, then Resume and confirm it's reachable again.
