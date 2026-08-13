<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithResume
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function resumeNamespace(): string
    {
        return ClusterTool::RESUME->namespace();
    }

    protected function resumeKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isResumeInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment resume-reactive -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readResumeSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'resume-reactive-secrets', $key);
    }

    protected function resolveResumeHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::RESUME;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function resumeAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->resumeKubectl($context);
        $ns = $this->resumeNamespace();

        if (! $this->isResumeInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveResumeHostReadOnly($env, $config),
            'label' => 'Reactive Resume',
        ];
    }
}
