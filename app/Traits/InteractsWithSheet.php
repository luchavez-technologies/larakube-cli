<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
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
    protected function isSheetInstalled(string $kubectl, string $ns, string|ToolInstance|null $instance = null): bool
    {
        $k = Kubectl::fromPrefix($kubectl);
        $toolInstance = $instance instanceof ToolInstance
            ? $instance
            : ($instance !== null && $instance !== '' ? ToolInstance::forInstance(ClusterTool::SHEETS, $instance) : null);

        if ($toolInstance !== null) {
            return $k->hasDeployment($ns, $toolInstance->deployment());
        }

        return $k->hasDeploymentLabelled($ns, 'larakube.io/tool=sheets');
    }

    /** Read database password. */
    protected function readSheetDbPassword(string $kubectl, string $ns, string|ToolInstance $instance = ''): ?string
    {
        return $this->readSheetSecret($kubectl, $ns, 'db-password', $instance);
    }

    /** Read a key from the teable-secrets secret (base64-decoded), or null. */
    protected function readSheetSecret(string $kubectl, string $ns, string $key, string|ToolInstance $instance = ''): ?string
    {
        $toolInstance = $instance instanceof ToolInstance
            ? $instance
            : ($instance !== '' ? ToolInstance::forInstance(ClusterTool::SHEETS, $instance) : null);

        return $toolInstance !== null
            ? $this->readClusterSecretKey($kubectl, $ns, $toolInstance->secret(), $key)
            : null;
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
