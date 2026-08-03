<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithSheet
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    /** The namespace the sheet stack lives in. */
    protected function sheetNamespace(): string
    {
        return ClusterTool::SHEETS->namespace();
    }

    /** Build the kubectl command. */
    protected function sheetKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Sheet Deployment present? */
    protected function isSheetInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment sheet-teable -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
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
        $kubectl = $this->sheetKubectl($context);
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
