<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithSheet
{
    use ResolvesEnvironmentContext;

    /** The namespace the sheet stack lives in. */
    protected function sheetNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command. */
    protected function sheetKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Sheet (nocodb) Deployment present? */
    protected function isSheetInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment sheet-nocodb -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /** Read database password. */
    protected function readSheetDbPassword(string $kubectl, string $ns): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret sheet-secrets -n {$ns} -o jsonpath='{.data.db-password}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
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
            'label' => 'NocoDB',
        ];
    }
}
