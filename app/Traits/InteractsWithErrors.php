<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

trait InteractsWithErrors
{
    use ReadsClusterSecrets;

    /** The namespace the GlitchTip stack lives in. */
    protected function errorsNamespace(): string
    {
        return ClusterTool::ERRORS->namespace();
    }

    /** GlitchTip web Deployment present? A cheap "is GlitchTip installed" probe. */
    protected function isErrorsInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeployment($ns, 'glitchtip-web');
    }

    /** Decrypt and read the GlitchTip admin password from the larakube Secret. */
    protected function readErrorsAdminPassword(string $kubectl, string $ns): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret errors-secrets -n {$ns} -o jsonpath='{.data.password}'",
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
        $kubectl = Kubectl::forContext($context)->prefix();
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
