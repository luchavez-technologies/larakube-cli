<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithDesign
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function designNamespace(): string
    {
        return ClusterTool::DESIGN->namespace();
    }

    protected function designKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isDesignInstalled(string $kubectl, string $ns, ?string $instance = null): bool
    {
        $deployment = ClusterTool::DESIGN->deploymentName($instance);
        $out = Process::run("{$kubectl} get deployment {$deployment} -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readDesignSecret(string $kubectl, string $ns, string $key, ?string $instance = null): ?string
    {
        $ref = ClusterTool::DESIGN->dbSecretRef($instance);
        $secretName = $ref['secret'] ?? (($instance === null || $instance === '') ? 'design-secrets' : "design-secrets-{$instance}");

        return $this->readClusterSecretKey($kubectl, $ns, $secretName, $key);
    }

    protected function resolveDesignHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::DESIGN;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function designAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->designKubectl($context);
        $ns = $this->designNamespace();

        if (! $this->isDesignInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveDesignHostReadOnly($env, $config),
            'label' => 'Penpot',
        ];
    }
}
