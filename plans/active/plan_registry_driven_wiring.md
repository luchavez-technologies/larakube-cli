# Implementation Plan: Registry-Driven Tool Resolution for `*:wire`, `*:unwire`, `*:remove`, and `tool:remove` Commands

## Goal Description
Refactor LaraKube's orchestration wiring commands (`mail:wire`, `sso:wire`, `secrets:wire`, `vpn:wire`), unwiring commands (`mail:unwire`, `sso:unwire`, `secrets:unwire`, `vpn:unwire`), tool removal base (`AbstractToolRemoveCommand` for `{tool}:remove`), and global tool removal helper (`tool:remove` via `ResolvesClusterTool`) to use the cluster Tool Registry (`larakube-tools-registry` via `InteractsWithToolRegistry`) as the primary source of truth for target resolution, with fallback probes against live Kubernetes deployments when the registry is uninitialized or missing.

---

## Architectural Analysis & Current Limitations

### The Problem
1. **Hardcoded Deployment Probes in `*:wire` / `*:unwire`**: Currently queries static `ClusterTool::shippedCases()` enums and probes fixed default deployment names via `kubectl get deployment`, bypassing `larakube-tools-registry` metadata and missing multi-instance deployments (e.g. `notes-outline-sister`).
2. **Hardcoded Single-Instance Assumptions in `*:remove`**: In `AbstractToolRemoveCommand`, omitting `--domain` assumes `['main']`. If multiple instances of a tool are registered in `larakube-tools-registry` (e.g. `notes:main` and `notes:sister`), `{tool}:remove` cannot prompt the operator to choose which instance to remove.
3. **Registry-Only Brittleness in `tool:remove`**: `ResolvesClusterTool` queries `getRegisteredTools` only. If the registry is empty or uninitialized on an existing cluster, `tool:remove` reports `"No shared tools available to remove"`, failing to detect un-registered live deployments.

---

## Agreed Design Decisions (Resolved via `/grill-me`)

> [!IMPORTANT]
> **Key Decisions**:
> 1. **Dual Resolution Strategy**:
>    - **Primary**: Read `larakube-tools-registry` Secret (`getRegisteredTools($kubectl)`). Filter entries by tool capabilities (`smtpEnv`, `oidcEnv`, `dbSecretRef`, `vpnMiddlewareTarget`) or removal status.
>    - **Fallback**: If the registry is empty or missing, fall back to live K8s deployment probes (`deploymentExists` / `isToolPresentOnCluster`) across `ClusterTool::shippedCases()`.
> 2. **Multi-Instance Tool Removal (`{tool}:remove`)**:
>    - When `{tool}:remove` runs interactively without `--domain=`, check the registered instances for `{tool}` via `getRegisteredTools($kubectl)`.
>    - If multiple instances exist (e.g., `main` on `notes.dev.test` and `sister` on `sister.dev.test`), display an interactive `select` prompt listing all instances with their host names so the operator can choose which specific instance to remove.
>    - Support `--all` flag to remove all instances of `{tool}` in a single pass.
> 3. **Interactive Multi-Instance Selection for Wiring**:
>    - Group options by Tool first (e.g., `Outline`, `PocketBase`).
>    - If the selected tool has multiple registered instances, show a sub-prompt (`select`) to choose the target instance/domain.
> 4. **`--all` Flag Execution**:
>    - Wire every installed tool AND every registered instance of that tool across the cluster.

---

## Proposed Changes

### 1. Unified Registry & Fallback Helper Trait
#### [MODIFY] [`cli/app/Traits/InteractsWithToolRegistry.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/InteractsWithToolRegistry.php)
- Add `resolveCapableToolInstances(string $kubectl, callable $capabilityFilter): array`
  - Queries `getRegisteredTools($kubectl)`.
  - Filters by `$capabilityFilter(ClusterTool $tool, string $engine, string $instance)`.
  - If registry is empty, falls back to probing live K8s deployments across `ClusterTool::shippedCases()`.

---

### 2. Multi-Instance Teardown Base & Global Removal Helper

#### [MODIFY] [`cli/app/Commands/Tool/AbstractToolRemoveCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Tool/AbstractToolRemoveCommand.php)
- Refactor `resolveInstanceTargets($kubectl)`:
  - If `--domain=` is specified, resolve that target directly.
  - If `--domain=` is omitted in interactive mode:
    - Query registered instances for `$this->tool()`.
    - If multiple instances exist, show a Laravel Prompts `select()` prompt displaying `instance (host)` for the user to choose.
    - If `--all` option is passed, return all registered instances.
  - Fall back to live deployment probes if no registry entries exist.

#### [MODIFY] [`cli/app/Traits/ResolvesClusterTool.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/ResolvesClusterTool.php)
- Refactor `resolveTools($kubectl, $actionHint)` for `tool:remove`:
  - Query `getRegisteredTools($kubectl)` as primary source of truth.
  - Fall back to checking `isToolPresentOnCluster($kubectl, $tool)` across `ClusterTool::shippedCases()` if the registry is empty or missing.

#### [MODIFY] [`cli/app/Commands/Tool/ToolRemoveCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Tool/ToolRemoveCommand.php)
- Add `--all` and `--domain` options passthrough to `{tool}:remove`.

---

### 3. Refactor Wiring & Unwiring Commands

#### [MODIFY] [`cli/app/Commands/Mail/MailWireCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Mail/MailWireCommand.php)
- Refactor `resolveTargets($kubectl)` to query registry + fallback probes.
- Update `--all` to iterate over all capability-matched tool instances.
- Update interactive prompt to group by tool and prompt for instance if multiple exist.

#### [MODIFY] [`cli/app/Commands/Mail/MailUnwireCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Mail/MailUnwireCommand.php)
- Refactor target resolution to use registry + fallback probes.

#### [MODIFY] [`cli/app/Commands/Sso/SsoWireCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Sso/SsoWireCommand.php)
- Refactor `resolveTool($kubectl)` to use registry + fallback probes.
- Support instance sub-prompts for multi-instance OIDC tools.

#### [MODIFY] [`cli/app/Commands/Sso/SsoUnwireCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Sso/SsoUnwireCommand.php)
- Refactor target resolution to use registry + fallback probes.

#### [MODIFY] [`cli/app/Commands/Secrets/SecretsWireCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Secrets/SecretsWireCommand.php)
- Refactor `resolveTargets($kubectl)` to query registry + fallback probes.
- Update `--all` to wire DB rotation across all registered instances.

#### [MODIFY] [`cli/app/Commands/Secrets/SecretsUnwireCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Secrets/SecretsUnwireCommand.php)
- Refactor target resolution to use registry + fallback probes.

#### [MODIFY] [`cli/app/Commands/Vpn/VpnWireCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Vpn/VpnWireCommand.php)
- Refactor target resolution to use registry + fallback probes.

#### [MODIFY] [`cli/app/Commands/Vpn/VpnUnwireCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Vpn/VpnUnwireCommand.php)
- Refactor target resolution to use registry + fallback probes.

---

## Verification Plan

### Automated Tests
1. **Pest Feature Tests**:
   ```bash
   php vendor/bin/pest tests/Feature/ToolRemoveCommandTest.php tests/Feature/MailWireCommandTest.php tests/Feature/SsoWireCommandTest.php tests/Feature/SecretsWireCommandTest.php tests/Feature/VpnWireCommandTest.php tests/Feature/NamedToolInstanceTest.php
   ```
2. **Pint Formatting**:
   ```bash
   php vendor/bin/pint
   ```
3. **PHPStan Analysis**:
   ```bash
   php vendor/bin/phpstan analyse --memory-limit=1G
   ```
