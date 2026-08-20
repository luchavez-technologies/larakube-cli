<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithPaste
{
    protected function pasteNamespace(): string
    {
        return ClusterTool::PASTE->namespace();
    }

    protected function pasteKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isPasteInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment paste-yopass -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function resolvePasteHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::PASTE;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function pasteAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->pasteKubectl($context);
        $ns = $this->pasteNamespace();

        if (! $this->isPasteInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolvePasteHostReadOnly($env, $config),
            'label' => 'Yopass',
        ];
    }
}
