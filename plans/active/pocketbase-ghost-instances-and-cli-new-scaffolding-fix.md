# PocketBase Ghost Duplicate Instance & CLI `new` Scaffolding Fix

## Root Cause Analysis

### 1. PocketBase Ghost Duplicate Cards (3 cards instead of 2)
- **ToolAddCommand (`cli/app/Commands/Tool/ToolAddCommand.php`)**:
  When a tool like PocketBase was installed via `tool:add` (or the Desktop UI), it proxied to `pocketbase:init` (`data:init`). The initializer already registered the deployment with its specific instance name (`pocket-test`) and public ingress host (`pocket-test.larakube.app`).
  However, line 80 in `ToolAddCommand.php` had a post-execution fallback:
  ```php
  $this->registerTool($kubectl, $tool);
  ```
  Because `$instance` was omitted (`null`), and the registry already contained instances of this tool, `ToolRegistry::matchIndex` returned `null` (since there was no sole match), which caused `ToolRegistry::register()` to append a NEW blank registry row `['tool' => 'pocketbase', 'instance' => null, 'host' => null]`.
  This ghost row had no host or instance, so LaraKube Desktop displayed it as a 3rd PocketBase card with "No public ingress address".
- **ToolListCommand Pruning (`cli/app/Commands/Tool/ToolListCommand.php`)**:
  `pruneBogusRegistryEntries()` previously only pruned empty ghost entries for *single-instance* tools (`! $tool->supportsMultipleInstances()`). Multi-instance tools were missing this cleanup logic.
- **Desktop UI Filtering (`desktop/resources/js/pages/tools/index.tsx`)**:
  Desktop deduplicated single-instance tools, but for multi-instance tools, an entry lacking both `instance` and `host` was still rendered if present in the cluster registry.

### 2. `larakube new` Failing on `Orchestrating infrastructure manifests...: failed`
- **Orchestration Defaulting to `buildImage: true` (`cli/app/Traits/GeneratesProjectInfrastructure.php` & `cli/app/Commands/NewCommand.php`)**:
  In `NewCommand.php`, `$this->orchestrateProjectScaffolding($config)` was invoked with default parameters. `orchestrateProjectScaffolding` defaulted `$buildImage = true`, which called `buildImage($config)` (`docker build`).
  During scaffolding (`new`), the user's local application is just being generated. Image building, cluster sideloading, and container deployment belong to `larakube up`, not `new`. When `docker build` was triggered mid-scaffolding, any Docker daemon timeout, BuildKit latency, or interrupted process resulted in an immediate `: failed` state.
- **Error Visibility in `withSpin` (`cli/app/Traits/LaraKubeOutput.php`)**:
  When a task wrapped inside `$this->withSpin(...)` threw a `\Throwable`, the underlying exception message was swallowed by Laravel Zero's task helper and simply emitted `: failed` without surfacing the reason to the user.
- **Null Safety in Docker Command (`cli/app/Traits/InteractsWithDocker.php`)**:
  `getDockerCommand` line 91 called `$this->getProjectConfig($path)->getPhpImage(true)` without null safety, which risked crashing if `.larakube.json` was not yet loaded.

---

## Applied Solutions

1. **`ToolAddCommand.php`**:
   - Guarded `registerTool`: Only re-register single-instance tools that were not registered by their own initializer. Multi-instance tools are never registered without explicit instance and host context.
2. **`ToolListCommand.php`**:
   - Updated `pruneBogusRegistryEntries` to automatically detect and prune multi-instance ghost entries that lack both host and named instance, or have no active deployment on the cluster.
3. **`desktop/resources/js/pages/tools/index.tsx`**:
   - Added a filter in `installedAll` skipping multi-instance tool entries that lack both `instance` and `host` when other valid instances exist.
4. **`NewCommand.php` & `GeneratesProjectInfrastructure.php`**:
   - Changed `buildImage` default in `orchestrateProjectScaffolding` to `false`.
   - Updated `NewCommand.php` to explicitly pass `buildImage: false` and check `$scaffolded` return status with proper error output.
5. **`LaraKubeOutput.php`**:
   - Updated `withSpin` to catch `\Throwable $e`, print the actionable message via `$this->laraKubeError($e->getMessage())`, and return `false`, ensuring transparent error reporting rather than silent `: failed` states.
6. **`InteractsWithDocker.php`**:
   - Added null-safe fallback for `$this->getProjectConfig($path)?->getPhpImage(true) ?? 'docker.io/serversideup/php:8.4-fpm-nginx-alpine'`.

---

## Verification
- `cli/`:
  - `composer format`: Clean (Rector passed, Pint formatted).
  - `composer analyse` (PHPStan Level 6+ with 2GB limit): Clean (0 errors).
  - Pest test suite:
    - `tests/Feature/ToolListCommandTest.php`: Passed (including new ghost multi-instance pruning test).
    - `tests/Feature/NewCommandScriptedRunTest.php`: Passed (including `buildImage: false` assertion).
- `desktop/`:
  - `npm run types:check`: Clean (0 errors).
  - `npm run build`: Clean (built in 5.46s).
  - `vendor/bin/pest`: 121 passed (0 failures).
