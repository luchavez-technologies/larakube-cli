<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

trait InteractsWithNotes
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function notesNamespace(): string
    {
        return ClusterTool::NOTES->namespace();
    }

    protected function isNotesInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeployment($ns, 'notes-outline');
    }

    protected function readNotesSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'notes-secrets', $key);
    }

    protected function readNotesSecretKey(string $kubectl, string $ns, string $secretName, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, $secretName, $key);
    }

    protected function resolveNotesHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::NOTES;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function notesAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->notesNamespace();

        if (! $this->isNotesInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveNotesHostReadOnly($env, $config),
            'label' => 'Outline',
        ];
    }
}
