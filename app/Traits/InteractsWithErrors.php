<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithErrors
{
    use ReadsClusterSecrets;

    /** The namespace the GlitchTip stack lives in. */
    protected function errorsNamespace(): string
    {
        return ClusterTool::ERRORS->namespace();
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function errorsKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** GlitchTip web Deployment present? A cheap "is GlitchTip installed" probe. */
    protected function isErrorsInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment glitchtip-web -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /** Decrypt and read the GlitchTip admin password from the larakube Secret. */
    protected function readErrorsAdminPassword(string $kubectl, string $ns): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret glitchtip-admin -n {$ns} -o jsonpath='{.data.password}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /**
     * Read-only GlitchTip host for an env: local → errors.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveErrorsHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::ERRORS;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Resolve GlitchTip's access details for display.
     * Returns null when GlitchTip isn't installed.
     *
     * @return array{host: ?string, password: ?string, label: string}|null
     */
    protected function errorsAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->errorsKubectl($context);
        $ns = $this->errorsNamespace();

        if (! $this->isErrorsInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveErrorsHostReadOnly($env, $config),
            'password' => $this->readErrorsAdminPassword($kubectl, $ns),
            'label' => 'GlitchTip',
        ];
    }
}
