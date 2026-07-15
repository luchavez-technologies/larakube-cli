<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Mint per-app per-environment, namespace-scoped deploy credentials.
 *
 * The k3s/cluster admin cert stays on the operator's machine (it's the
 * bootstrapping authority and never leaves). Each {app}-{env} namespace gets a
 * `deployer` ServiceAccount whose token can touch ONLY that namespace — that's
 * the credential handed to CI / used for the dogfooded apply.
 *
 * Why a plain namespaced Role (not a ClusterRole): a cloud overlay emits only
 * namespaced Kinds (PV/ClusterRole/MetalLB are gated to local/isSystem()), so a
 * Role is sufficient and least-privilege by construction.
 *
 * The builders are PURE (no I/O) so they're unit-testable; the orchestration
 * helpers at the bottom (ensureScopedRbac / mintScopedKubeconfig) run them and do
 * the kubectl I/O, shared by cloud:deploy and cloud:configure --only=ci.
 * See plans/active/scoped-rbac-deploy.md.
 */
trait InteractsWithScopedRbac
{
    /** Deterministic ServiceAccount/Role/RoleBinding name. Pure. */
    public function deployerName(): string
    {
        return 'deployer';
    }

    /** Common labels stamped on everything we mint (for cluster:users). Pure. */
    public function scopedRbacLabels(string $app, string $env): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'larakube',
            'larakube.dev/app' => $app,
            'larakube.dev/env' => $env,
        ];
    }

    /**
     * SA + namespaced Role + RoleBinding manifest for {app}-{env}. The Role's
     * rules are derived from the Kinds a cloud overlay actually emits — all
     * namespaced. Pure (returns YAML; caller applies with the admin context).
     */
    public function scopedRbacManifest(string $namespace, string $app, string $env, ?string $sa = null): string
    {
        $sa ??= $this->deployerName();
        $labels = "    app.kubernetes.io/managed-by: larakube\n"
            ."    larakube.dev/app: {$app}\n"
            ."    larakube.dev/env: {$env}";

        return <<<YAML
apiVersion: v1
kind: ServiceAccount
metadata:
  name: {$sa}
  namespace: {$namespace}
  labels:
{$labels}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: {$sa}
  namespace: {$namespace}
  labels:
{$labels}
rules:
  - apiGroups: ["apps"]
    resources: ["deployments", "statefulsets", "replicasets"]
    verbs: ["get", "list", "watch", "create", "update", "patch", "delete"]
  - apiGroups: ["apps"]
    resources: ["deployments/status", "statefulsets/status"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["batch"]
    resources: ["cronjobs", "jobs"]
    verbs: ["get", "list", "watch", "create", "update", "patch", "delete"]
  - apiGroups: [""]
    resources: ["services", "configmaps", "secrets", "persistentvolumeclaims", "serviceaccounts"]
    verbs: ["get", "list", "watch", "create", "update", "patch", "delete"]
  - apiGroups: [""]
    resources: ["pods", "pods/log"]
    verbs: ["get", "list", "watch"]
  - apiGroups: [""]
    resources: ["pods/exec"]
    verbs: ["create", "get"]
  - apiGroups: [""]
    resources: ["events"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["networking.k8s.io"]
    resources: ["ingresses"]
    verbs: ["get", "list", "watch", "create", "update", "patch", "delete"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: {$sa}
  namespace: {$namespace}
  labels:
{$labels}
subjects:
  - kind: ServiceAccount
    name: {$sa}
    namespace: {$namespace}
roleRef:
  kind: Role
  name: {$sa}
  apiGroup: rbac.authorization.k8s.io
YAML;
    }

    /**
     * Short-lived token for the local dogfooded apply — used immediately, then
     * discarded. Optional duration (seconds); default is the cluster default (1h).
     * Pure.
     */
    public function createTokenCommand(string $context, string $namespace, ?string $sa = null, ?int $durationSeconds = null): string
    {
        $sa ??= $this->deployerName();
        $cmd = $this->rbacKubectl($context)
            .' -n '.escapeshellarg($namespace)
            .' create token '.escapeshellarg($sa);

        if ($durationSeconds !== null) {
            $cmd .= ' --duration='.((int) $durationSeconds).'s';
        }

        return $cmd;
    }

    /**
     * Long-lived, Secret-bound SA token for CI (non-expiring, standard pattern).
     * Apply this, wait for K8s to populate `.data.token`, then read it. Pure.
     */
    public function tokenSecretManifest(string $namespace, ?string $sa = null, ?string $secretName = null): string
    {
        $sa ??= $this->deployerName();
        $secretName ??= $sa.'-token';

        return <<<YAML
apiVersion: v1
kind: Secret
metadata:
  name: {$secretName}
  namespace: {$namespace}
  labels:
    app.kubernetes.io/managed-by: larakube
  annotations:
    kubernetes.io/service-account.name: {$sa}
type: kubernetes.io/service-account-token
YAML;
    }

    /** Read the cluster API server URL from the admin context (CA inlined). Pure. */
    public function clusterServerCommand(string $context): string
    {
        return $this->rbacKubectl($context).' config view --minify --flatten'
            .' -o jsonpath='.escapeshellarg('{.clusters[0].cluster.server}');
    }

    /** Read the cluster CA (base64) from the admin context. Pure. */
    public function clusterCaDataCommand(string $context): string
    {
        return $this->rbacKubectl($context).' config view --minify --flatten'
            .' -o jsonpath='.escapeshellarg('{.clusters[0].cluster.certificate-authority-data}');
    }

    /**
     * Assemble a standalone, namespace-scoped kubeconfig from its parts. This is
     * the artifact uploaded as {ENV}_KUBECONFIG (CI) or used for the local
     * dogfooded apply. Pure.
     */
    public function assembleScopedKubeconfig(
        string $clusterName,
        string $server,
        string $caData,
        string $namespace,
        string $token,
        ?string $user = null,
    ): string {
        $user ??= $this->deployerName();

        return <<<YAML
apiVersion: v1
kind: Config
clusters:
  - name: {$clusterName}
    cluster:
      server: {$server}
      certificate-authority-data: {$caData}
contexts:
  - name: {$namespace}
    context:
      cluster: {$clusterName}
      namespace: {$namespace}
      user: {$user}
current-context: {$namespace}
users:
  - name: {$user}
    user:
      token: {$token}
YAML;
    }

    // --- Orchestration (I/O) — runs the pure builders above. Shared by
    //     cloud:deploy (dogfooded apply) and cloud:configure --only=ci (CI handoff). ---

    /**
     * Apply the SA + namespaced Role + RoleBinding for {app}-{env} using the
     * ADMIN context (idempotent). Returns false on failure.
     */
    public function ensureScopedRbac(string $adminContext, string $namespace, string $app, string $env): bool
    {
        $file = tempnam(sys_get_temp_dir(), 'lk_rbac_');
        file_put_contents($file, $this->scopedRbacManifest($namespace, $app, $env));
        $result = Process::run($this->rbacKubectl($adminContext).' apply -f '.escapeshellarg($file));
        @unlink($file);

        return $result->successful();
    }

    /**
     * Mint a LONG-LIVED, Secret-bound SA token and assemble a standalone,
     * namespace-scoped kubeconfig from it — for CI. Applies the token Secret
     * (admin), polls until k8s populates it (it's async), then builds the
     * kubeconfig from the cluster server URL + the Secret's CA + token. Returns
     * the kubeconfig YAML, or null on failure.
     */
    public function mintScopedKubeconfig(string $adminContext, string $namespace, ?string $sa = null): ?string
    {
        $sa ??= $this->deployerName();
        $secretName = $sa.'-token';
        $ctx = $this->rbacKubectl($adminContext);
        $ns = escapeshellarg($namespace);
        $secret = escapeshellarg($secretName);

        // 1. Apply the bound-token Secret (admin).
        $file = tempnam(sys_get_temp_dir(), 'lk_tok_');
        file_put_contents($file, $this->tokenSecretManifest($namespace, $sa, $secretName));
        $applied = Process::run($ctx.' apply -f '.escapeshellarg($file));
        @unlink($file);
        if (! $applied->successful()) {
            return null;
        }

        // 2. Poll until k8s populates .data.token (controller fills it in async).
        $token = '';
        for ($i = 0; $i < 15; $i++) {
            $b64 = trim(Process::run($ctx.' -n '.$ns.' get secret '.$secret.' -o jsonpath='.escapeshellarg('{.data.token}'))->output());
            if ($b64 !== '') {
                $token = (string) base64_decode($b64);
                break;
            }
            sleep(1);
        }
        if ($token === '') {
            return null;
        }

        // 3. CA — prefer the Secret's own ca.crt (already base64) for a
        //    self-contained config; fall back to the admin context's CA.
        $caData = trim(Process::run($ctx.' -n '.$ns.' get secret '.$secret.' -o jsonpath='.escapeshellarg('{.data.ca\.crt}'))->output());
        if ($caData === '') {
            $caData = trim(Process::run($this->clusterCaDataCommand($adminContext))->output());
        }

        // 4. Server URL from the admin context.
        $server = trim(Process::run($this->clusterServerCommand($adminContext))->output());
        if ($server === '' || $caData === '') {
            return null;
        }

        return $this->assembleScopedKubeconfig($adminContext, $server, $caData, $namespace, $token);
    }

    /**
     * Poll a bound-token Secret until k8s populates `.data.token` (it's async),
     * returning the decoded token or null on timeout. The Secret must already be
     * applied and reference an existing ServiceAccount.
     */
    public function pollSecretToken(string $adminContext, string $namespace, string $secretName): ?string
    {
        $base = $this->rbacKubectl($adminContext).' -n '.escapeshellarg($namespace)
            .' get secret '.escapeshellarg($secretName).' -o jsonpath=';

        for ($i = 0; $i < 15; $i++) {
            $b64 = trim(Process::run($base.escapeshellarg('{.data.token}'))->output());
            if ($b64 !== '') {
                return (string) base64_decode($b64);
            }
            sleep(1);
        }

        return null;
    }

    /** The CA (base64) from a bound-token Secret, falling back to the admin context's CA. */
    public function readSecretCaData(string $adminContext, string $namespace, string $secretName): string
    {
        $ca = trim(Process::run(
            $this->rbacKubectl($adminContext).' -n '.escapeshellarg($namespace)
            .' get secret '.escapeshellarg($secretName).' -o jsonpath='.escapeshellarg('{.data.ca\.crt}'),
        )->output());

        return $ca !== '' ? $ca : trim(Process::run($this->clusterCaDataCommand($adminContext))->output());
    }

    /** kubectl client >= 1.24 — needed for bound-token Secrets / `create token`. */
    public function kubectlSupportsTokens(): bool
    {
        $json = Process::run('kubectl version --client -o json')->output();
        if ($json !== '' && preg_match('/"minor":\s*"(\d+)/', $json, $m)) {
            return (int) $m[1] >= 24;
        }

        $plain = Process::run('kubectl version --client')->output();
        if ($plain !== '' && preg_match('/v1\.(\d+)/', $plain, $m)) {
            return (int) $m[1] >= 24;
        }

        return true; // can't determine → don't block.
    }

    /**
     * `kubectl --context X`, pinned to ~/.kube/config — that's where LaraKube
     * always merges contexts, but a bare `kubectl` follows the shell's own
     * $KUBECONFIG when one is set. This trait composes no other trait, so it
     * builds its own pinned prefix rather than depending on one.
     */
    protected function rbacKubectl(string $context): string
    {
        return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl --context '.escapeshellarg($context);
    }
}
