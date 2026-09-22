# Plan · Canonical Resource Naming for `ClusterTool::LINK` (Kutt)

**Status:** Completed · **Owner:** larakube CLI (Mechanic jurisdiction) · **Started:** 2026-09-22 · **Completed:** 2026-09-22  
**Home:** `cli/plans/active/link-canonical-resource-naming.md`  
**Governing ADRs:** ADR 0021 (Canonical Resource Naming), ADR 0024 (Tool Remove Is Safe By Default)

---

## 1. Context & Rationale

Following the successful migration of `MONITOR`, `GIT`, `NOTES`, `FLOW`, `SIGN`, and `DATA` to `ResourceNaming::CANONICAL`, `LINK` (Kutt link shortener) was the next candidate in Group A (legacy bare/as-shipped names).

Previously, `LINK`:
- Used legacy bare Deployment and Service names: `link-kutt-{$instance}`
- Used legacy Ingress name: `link-{$instance}`
- Used legacy un-scoped secrets: `link-secrets`, `link-smtp`, `link-oidc`
- Allocated legacy Commons DB: `link_kutt_{$dbInstance}` and Redis key: `link_kutt_{$instance}`
- Lacked standard LaraKube identity labels (`larakube.io/tool`, `larakube.io/component`, `larakube.io/instance`) on workloads and Ingress
- Had `usesBundledStorage()` returning `true` on missing `link-secrets`, which skipped Commons DB drop on `--purge`

Live cluster inspection on production (`larakube-159.89.205.239`) confirmed **zero** running `link` or `kutt` instances, so this migration completed with zero production disruption.

---

## 2. Target Architecture (ADR 0021 & ADR 0024)

1. **Enum & Contract**:
   - `ClusterTool::LINK` moved to `ResourceNaming::CANONICAL`.
   - `ClusterTool::LINK` added to `hasInstanceAwareRemoval()`.
   - `LinkTool`:
     - `canonicalComponentName()` is `'kutt'`.
     - `commonsRedisKeys()` returns `['kutt']` (producing Redis tenant `kutt_{$instance}`).
     - `canonicalDatabaseList()` declares `['kutt']` (producing PostgreSQL DB `kutt_{$dbInstance}`).
     - `smtpEnv()`, `oidcEnv()`, `dbSecretRef()` derive canonical names from `ToolInstance::forInstance(ClusterTool::LINK, $instance)`.
2. **Workloads & Views**:
   - `resources/views/k8s/link/shared.blade.php`:
     - Deployment & Service: `kutt-{$instance}` (via `$deployName` / `$serviceName`).
     - Secrets: `kutt-secrets-{$instance}`, optional `kutt-smtp-{$instance}`, optional `kutt-oidc-{$instance}`.
     - Injects LaraKube identity labels across Deployment, Pod template, Service.
   - `resources/views/k8s/link/ingress.blade.php`:
     - Ingress name: `kutt-{$instance}` (via `$ingressName`).
     - Backend Service: `kutt-{$instance}`.
     - Injects LaraKube identity labels.
3. **Init & Remove Commands**:
   - `LinkInitCommand`:
     - Strictly derives `$names = ToolInstance::forHost(ClusterTool::LINK, $host)`.
     - Allocates database `$names->database()` (`kutt_{$dbInstance}`).
     - Allocates Redis tenant `$names->redisTenant()` (`kutt_{$instance}`).
     - Writes secrets to `$names->secretName('secrets')` (`kutt-secrets-{$instance}`).
     - Registers deployed tool with canonical identity labels.
   - `LinkRemoveCommand`:
     - Teardown targets strictly canonical resources (`kutt-{$instance}`, `kutt-secrets-{$instance}`, `kutt-smtp-{$instance}`, `kutt-oidc-{$instance}`) with zero legacy fallbacks.
     - Requires `--purge` to drop Commons DB and Redis index per ADR 0024.
4. **Traits**:
   - `InteractsWithLink`:
     - `readLinkSecret()` strictly queries `$toolInstance->secret()`, with zero legacy fallback names.
     - `isLinkInstalled()` strictly checks canonical deployment `$toolInstance->deployment()`, or `larakube.io/tool=link` label fleet-wide.

---

## 3. Testing & Verification Gates (Byte-Green)

1. Unit tests:
   - `tests/Unit/ClusterToolLifecycleTest.php`: verifies `LINK` drops category on Commons tenants (`kutt` DB and `kutt` Redis) and has instance-aware removal.
   - `tests/Unit/ToolInstanceTest.php`: verifies canonical naming derivations (`kutt`, `kutt-secrets-*`, `kutt-oidc-*`).
2. Feature tests:
   - `tests/Feature/LinkInitCommandTest.php`: verifies canonical manifests, secrets, and identity labels.
   - `tests/Feature/LinkRemoveCommandTest.php`: verifies single-instance, domain-targeted, all-instances, and `--purge` drops.
   - `tests/Feature/SecretsWireCommandTest.php`: verifies `secrets:wire --tool=link` with OpenBao static roles on canonical names.
3. Quality gates:
   - Pint: `vendor/bin/pint --test` (PASS)
   - Static analysis: `composer analyse` (0 errors)
   - Pest: all 70 tests passed (437 assertions)

---

## 4. Audit Trail

- 2026-09-22 · Verified prod cluster clean of `link` instances.
- 2026-09-22 · Moved `ClusterTool::LINK` to `ResourceNaming::CANONICAL` and `hasInstanceAwareRemoval()`.
- 2026-09-22 · Updated `LinkTool` (Redis keys `kutt`, canonical secret refs, and DB naming).
- 2026-09-22 · Updated `InteractsWithLink` trait with instance-aware secret reading and deployment checks.
- 2026-09-22 · Updated `resources/views/k8s/link/shared.blade.php` and `ingress.blade.php` with canonical names and identity labels.
- 2026-09-22 · Updated `LinkInitCommand` and `LinkRemoveCommand` per ADR 0021 and ADR 0024.
- 2026-09-22 · Created `LinkRemoveCommandTest.php` and updated `LinkInitCommandTest.php`, `ToolInstanceTest.php`, `SecretsWireCommandTest.php`.
- 2026-09-22 · Fully utilized OOP abstractions: `Kubectl` instance handles (`forContext()`), `ToolInstance` typed references (`$names->labels()`, `$names->database()`, `$names->secret()`), `ResourceRef` lists in `LinkRemoveCommand`, and polymorphic `string|ToolInstance` handling in `InteractsWithLink`.
- 2026-09-22 · Eradicated all legacy fallbacks across Link commands, traits, and tests. Pre-v1 strictly standardizes on 100% canonical naming without backward-compatibility crutches.
- 2026-09-22 · Graduated `link` off `toolNamingKnownDrift()` in `ToolNamingDriftTest.php`—init and remove now agree on every name with zero drift.
- 2026-09-22 · Passed all test suites in parallel (`--parallel`), Pint linter, and PHPStan (`composer analyse`).

