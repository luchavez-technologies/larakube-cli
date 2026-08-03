<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use Illuminate\Support\Facades\Process;

trait InteractsWithFlow
{
    use ResolvesEnvironmentContext;

    /** The namespace the flow stack lives in. */
    protected function flowNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command, optionally scoped to a specific context. */
    protected function flowKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Flow (n8n) Deployment present? */
    protected function isFlowInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment flow-n8n -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /** Read flow encryption key. */
    protected function readFlowEncryptionKey(string $kubectl, string $ns): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret flow-secrets -n {$ns} -o jsonpath='{.data.encryption-key}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /** Read flow database password. */
    protected function readFlowDbPassword(string $kubectl, string $ns): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret flow-secrets -n {$ns} -o jsonpath='{.data.db-password}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /**
     * Read-only Flow host for an env. Each engine has its own host (stored under
     * `flow-{engine}`); without an explicit engine, prefer whichever is recorded
     * (n8n first for back-compat) so flow:show still resolves.
     */
    protected function resolveFlowHostReadOnly(string $env, ?ConfigData $config, ?string $engine = null): ?string
    {
        $engines = $engine !== null ? [$engine] : ['n8n', 'windmill'];

        if ($env === 'local') {
            return $engines[0].'.'.GlobalConfigData::load()->getLocalTld();
        }

        foreach ($engines as $candidate) {
            $host = $config?->getEnvironment($env)?->hosts["flow-{$candidate}"] ?? null;
            if ($host !== null && $host !== '') {
                return $host;
            }
        }

        return null;
    }

    /** Resolve Flow's access details. */
    protected function flowAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->flowKubectl($context);
        $ns = $this->flowNamespace();

        if (! $this->isFlowInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveFlowHostReadOnly($env, $config),
            'label' => 'n8n',
        ];
    }
}
