# Implementation Plan: Registry-Driven Tool Resolution for `*:wire` and `*:unwire` Commands

## Goal Description
Refactor LaraKube's orchestration wiring commands (`mail:wire`, `sso:wire`, `secrets:wire`, `vpn:wire`) and their sibling unwiring commands (`mail:unwire`, `sso:unwire`, `secrets:unwire`, `vpn:unwire`) to use the cluster Tool Registry (`larakube-tools-registry` via `InteractsWithToolRegistry`) as the primary source of truth for target resolution, with fallback probes against live Kubernetes deployments when the registry is uninitialized or missing.

---

## Architectural Analysis & Current Limitations

### The Problem
Currently, `mail:wire`, `sso:wire`, `secrets:wire`, and `vpn:wire` resolve candidates by querying static `ClusterTool::shippedCases()` enums and probing fixed default deployment names via `kubectl get deployment`.

1. **Ignores Tool Registry Metadata**: `larakube-tools-registry` stores exact registered metadata (`tool`, `instance`, `host`, `aliases`, `engine`) for every installed tool. Probing raw Enums bypasses this metadata.
2. **Breaks Multi-Instance Deployments**: When an operator deploys a named instance (e.g. `notes` instance `sister` at `sister.dev.test`), hardcoded deployment probes (checking only `notes-outline`) miss named instances (e.g. `notes-outline-sister`).
3. **Slow Redundant K8s Probes**: Probing 20+ static enum cases against K8s API when zero or 1 tool is installed adds unnecessary command latency.

---

## Agreed Design Decisions (Resolved via `/grill-me`)

> [!IMPORTANT]
> **Key Decisions**:
> 1. **Dual Resolution Strategy**:
>    - **Primary**: Read `larakube-tools-registry` Secret (`getRegisteredTools($kubectl)`). Filter entries by tool capabilities (`smtpEnv`, `oidcEnv`, `dbSecretRef`, `vpnMiddlewareTarget`).
>    - **Fallback**: If the registry is empty or missing, fall back to live K8s deployment probes (`deploymentExists` / `isToolPresentOnCluster`) across `ClusterTool::shippedCases()`.
> 2. **Interactive Multi-Instance Selection**:
>    - Group options by Tool first (e.g., `Outline`, `PocketBase`).
>    - If the selected tool has multiple registered instances (e.g., `main` on `notes.dev.test` and `sister` on `sister.dev.test`), show a sub-prompt (`select`) to choose the specific instance/domain.
> 3. **`--all` Flag Multi-Instance Execution**:
>    - When `--all` is passed, iterate through and wire **every installed tool AND every registered instance** of that tool across the cluster.

---

## Proposed Changes

### 1. Unified Registry & Fallback Helper Trait
#### [MODIFY] [`cli/app/Traits/InteractsWithToolRegistry.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/InteractsWithToolRegistry.php)
- Add `resolveCapableToolInstances(string $kubectl, callable $capabilityFilter): array`
  - Queries `getRegisteredTools($kubectl)`.
  - Filters by `$capabilityFilter(ClusterTool $tool, string $engine, string $instance)`.
  - If registry is empty, falls back to probing live K8s deployments across `ClusterTool::shippedCases()`.

---

### 2. Refactor Commands

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
   php vendor/bin/pest tests/Feature/MailWireCommandTest.php tests/Feature/SsoWireCommandTest.php tests/Feature/SecretsWireCommandTest.php tests/Feature/VpnWireCommandTest.php tests/Feature/NamedToolInstanceTest.php
   ```
2. **Pint Formatting**:
   ```bash
   php vendor/bin/pint
   ```
3. **PHPStan Analysis**:
   ```bash
   php vendor/bin/phpstan analyse --memory-limit=1G
   ```
