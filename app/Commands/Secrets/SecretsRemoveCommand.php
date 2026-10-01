<?php

namespace App\Commands\Secrets;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Traits\InteractsWithSecrets;
use Illuminate\Support\Facades\Process;

class SecretsRemoveCommand extends AbstractToolRemoveCommand
{
    use InteractsWithSecrets;

    protected $signature = 'secrets:remove
        {environment=local  : Environment to remove the secrets engine from}
        {--context=         : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--domain=          : Not supported — the secrets engine has a single instance}
        {--purge            : Also destroy persistent data — delete OpenBao PVC and bootstrap secret. Irreversible.}
        {--force            : Skip the confirmation prompt (required for non-interactive runs)}';

    protected $description = 'Remove OpenBao secrets manager and External Secrets Operator from a cluster';

    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'secrets:remove' is deprecated. Please use 'openbao:remove' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SECRETS;
    }

    protected function teardownWarning(string $env): array
    {
        $lines = [
            "OpenBao Secrets Manager & External Secrets Operator will be REMOVED from '{$env}':",
            'OpenBao Deployment, Service, ConfigMap, Secrets, and ESO Controller in larakube-secrets',
        ];

        if ($this->option('purge')) {
            $lines[] = 'OpenBao persistent volume claim and bootstrap secret WILL BE DESTROYED.';
        } else {
            $lines[] = 'OpenBao storage PVC and secrets WILL BE PRESERVED.';
        }

        return $lines;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = $this->secretsNames($kubectl);

        // Without a registered install there is nothing of OpenBao's own to name.
        $ok = $names === null || $this->removeResources(
            'Removing OpenBao...',
            "{$kubectl} delete deployment/{$names->deployment()} service/{$names->deployment()} ingress/{$names->deployment()} "
            ."configmap/{$names->configMap('config')} serviceaccount/{$names->deployment()} "
            ."secret/{$names->secret(SecretKind::OIDC)} -n {$namespace} --ignore-not-found",
        );

        // Only one Deployment actually exists — eso.blade.php bundles the
        // controller into a single Deployment, not the cert-controller/webhook
        // split the real upstream ESO Helm chart uses. The other two names
        // here were dead no-ops (--ignore-not-found masked it) until 2026-07-31.
        $ok = $this->removeResources(
            'Removing External Secrets Operator...',
            "{$kubectl} delete deployment external-secrets -n {$namespace} --ignore-not-found",
        ) && $ok;

        // ClusterRole/ClusterRoleBinding are genuinely cluster-scoped RBAC for
        // the ServiceAccount just deleted with the namespace below — safe to
        // remove, they own nothing and nothing else references this exact
        // binding name.
        $ok = $this->removeResources(
            'Removing External Secrets Operator RBAC...',
            "{$kubectl} delete clusterrole external-secrets-controller clusterrolebinding external-secrets-controller --ignore-not-found",
        ) && $ok;

        // Cluster-scoped, like the binding above — the openbao ServiceAccount
        // it targets dies with the namespace below, but the binding itself
        // wouldn't (cluster-scoped RBAC objects don't cascade with a namespace).
        if ($names !== null) {
            $ok = $this->removeResources(
                "Removing OpenBao's Kubernetes-auth RBAC binding...",
                "{$kubectl} delete clusterrolebinding {$names->name('auth-delegator')} --ignore-not-found",
            ) && $ok;
        }

        if ($names !== null && $this->option('purge')) {
            Process::run("{$kubectl} delete pvc {$names->volume()} -n {$namespace} --ignore-not-found");
            Process::run("{$kubectl} delete secret {$names->secret()} -n {$namespace} --ignore-not-found");
        }

        Process::run("{$kubectl} delete namespace {$namespace} --ignore-not-found");

        // Deliberately NOT removing the external-secrets.io CRDs here. They're
        // cluster-scoped, shared infrastructure — ANY tool's ExternalSecret
        // (Forgejo, Stalwart, whatever else openbaoSyncConfig() covers) uses
        // them, not just OpenBao. Deleting a CRD cascades to delete every
        // custom resource of that type cluster-wide, and tool-es.blade.php
        // sets creationPolicy: Owner, so that cascade would ALSO delete the
        // actual K8s Secret objects those other apps are using right now —
        // confirmed live 2026-07-31 via a real ownerReference
        // (blockOwnerDeletion: true) on the forgejo Secret. secrets:remove's
        // job is "remove OpenBao from this environment," not "remove the
        // sync mechanism cluster-wide" — those are different scopes that
        // just happen to ship together via openbao:init today.
        return $ok;
    }
}
