<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

trait InteractsWithSheet
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    /** The namespace the sheet stack lives in. */
    protected function sheetNamespace(): string
    {
        return ClusterTool::SHEETS->namespace();
    }

    /** Sheet Deployment present? */
    protected function isSheetInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeployment($ns, 'sheet-teable');
    }

    /** Read database password. */
    protected function readSheetDbPassword(string $kubectl, string $ns): ?string
    {
        return $this->readSheetSecret($kubectl, $ns, 'db-password');
    }

    /** Read a key from the sheet-secrets secret (base64-decoded), or null. */
    protected function readSheetSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'sheet-secrets', $key);
    }

    /** Read-only Sheet host. */
    protected function resolveSheetHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::SHEET;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /** Resolve Sheet's access details. */
    protected function sheetAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->sheetNamespace();

        if (! $this->isSheetInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveSheetHostReadOnly($env, $config),
            'label' => 'Teable',
        ];
    }
}
