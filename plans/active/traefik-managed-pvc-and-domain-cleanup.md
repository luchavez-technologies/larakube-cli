# Traefik Managed PVC & Domain Disconnect Fix

## Overview
When changing Auto-SSL or re-applying Traefik on a managed Kubernetes cluster (GKE/EKS/DOKS), `deployTraefik` failed to preserve the existing PVC `storageClassName`. Because Kubernetes rejects mutating `spec.storageClassName` from its bound value (`standard-rwo`) to null, `kubectl apply` failed and Traefik was never rolled out with the updated Cloudflare token. Consequently, the Traefik pod continued running with stale environment variables, causing certificate issuance for newly bound domains (e.g. `pocketsize.larakube.app`) to fail.

Furthermore, the Desktop UI had no way to disconnect/remove stale ExternalDNS domain groups (e.g. `production` covering `necxsoft.com, nexa-web.site`), and `tls:prune` artificially blocked managed clusters from cleaning up abandoned certificates from Traefik's `acme.json`.

## Root Cause Analysis
1. **`deployTraefik` PVC Immutability Error**:
   - `k8s.traefik-managed.blade.php` conditionally renders `storageClassName: {{ $storageClass }}`.
   - In `ProvisionsK3sNode::deployTraefik`, `$storageClass` was never passed in `$viewData`.
   - On initial provisioning, `CloudProvisionGkeCommand` passed `storageClass => 'standard-rwo'`. Once bound, Kubernetes permanently locks `spec.storageClassName`.
   - Subsequent calls (`tls:init`, `traefik:setup`, `tls:remove`) omitted `storageClass`, causing `kubectl apply` to attempt setting `storageClassName: null`, which Kubernetes rejected with: `Forbidden: spec is immutable after creation except resources.requests and volumeAttributesClassName for bound claims`.
2. **Silent Failure in `applyAndVerifyRollout`**:
   - When `Process::run` failed during `kubectl apply`, `VerifiesKubernetesRollout` did not print `$applyResult->errorOutput()`, leaving the user with an uninformative `Could not apply the traefik manifest — see the output above` error.
3. **Stale Traefik Pod & Certificate Errors**:
   - Because `applyAndVerifyRollout` failed, the Traefik deployment was not updated and the pod was not restarted.
   - The Traefik container kept running with the 47-hour-old token in its environment (`CF_DNS_API_TOKEN`).
   - When Traefik attempted DNS-01 challenge for `pocketsize.larakube.app`, Cloudflare rejected it with `zone could not be found`.
4. **Missing UI Action to Disconnect ExternalDNS Domain Groups**:
   - Desktop's `DomainRow` displayed domain groups (`larakube-app`, `production`), but had no disconnect or delete action.
   - Operators could not remove stale groups without manually knowing the CLI syntax `external-dns:remove --group=...`.
5. **`tls:prune` Artificial Block on Managed Clusters**:
   - `TlsPruneCommand` checked `if ($this->traefikIsManaged($kubectl))` and aborted.
   - In reality, managed clusters simply store `acme.json` at `/data/acme.json` instead of `/acme/acme.json`. Both can be pruned via `kubectl exec`.

## Proposed Changes

### CLI
1. **`cli/app/Traits/ProvisionsK3sNode.php`**:
   - In `deployTraefik`: when `$isManaged` is true, detect the existing `storageClassName` from `traefik-acme` PVC (`get pvc traefik-acme -n traefik -o jsonpath={.spec.storageClassName}`) and pass it as `$viewData['storageClass']`.
   - Update log output from `(Single-Node Hero)` to `(Managed Ingress)` when `$isManaged` is true.
2. **`cli/app/Traits/VerifiesKubernetesRollout.php`**:
   - Print `$applyResult->errorOutput() ?: $applyResult->output()` when `kubectl apply` fails so error details are never swallowed.
3. **`cli/app/Commands/Tls/TlsPruneCommand.php`**:
   - Remove the `$this->traefikIsManaged()` bailout.
   - Dynamically select `$acmeFile = $this->traefikIsManaged($kubectl) ? '/data/acme.json' : '/acme/acme.json'`.

### Desktop
1. **`desktop/app/Enums/RunKind.php`**:
   - Add `DisconnectDomain = 'disconnect-domain'`.
2. **`desktop/app/Http/Controllers/ServerController.php`**:
   - Add `disconnectDomain(Request $request, string $server, StackCatalog $catalog, CliRunner $runner)`.
   - Runs `external-dns:remove production --group={$group} --context={$context} --force`.
3. **`desktop/routes/web.php`**:
   - Add route `POST /servers/{server}/domains/disconnect`.
4. **`desktop/app/Listeners/RecordRunOutput.php`**:
   - Handle `RunKind::DisconnectDomain` to forget DNS cache and dispatch `SyncServerDomainsJob`.
5. **`desktop/resources/js/pages/servers/show.tsx`**:
   - In `DomainRow`, add a Disconnect button with `Unlink` icon and confirmation dialog for each domain group.
   - In `SslRow`, surface unused certificates from `tlsReport.unusedCertificates` and prompt to prune them.

## Verification & Testing
- Unit & Feature tests in `cli/tests/` for `deployTraefik` storageClass resolution and `TlsPruneCommand` managed cluster support.
- Feature tests in `desktop/tests/` for `disconnectDomain` endpoint and `RunKind::DisconnectDomain`.
- Run `composer format`, `composer analyse`, `composer test`.
