<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Enums\SharedClusterService;

trait InteractsWithDashboard
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function dashboardNamespace(): string
    {
        return 'larakube-shared';
    }

    /**
     * The OIDC credentials sso:wire put on this instance, read back so a re-run
     * renders them again; null when it was never wired. The Secret's name comes
     * from ToolInstance (ADR 0021): a hand-built legacy name here once read
     * nothing, and the re-run then rendered the Secret empty and erased them.
     *
     * @return array{issuer: string, client_id: string, client_secret: string}|null
     */
    protected function readDashboardWiredOidc(string $kubectl, string $ns, string $instance): ?array
    {
        $secret = ToolInstance::forInstance(ClusterTool::HEADLAMP, $instance)->secret(SecretKind::OIDC);
        $issuer = $this->readClusterSecretKey($kubectl, $ns, $secret, 'HEADLAMP_CONFIG_OIDC_IDP_ISSUER_URL');
        $clientId = $this->readClusterSecretKey($kubectl, $ns, $secret, 'HEADLAMP_CONFIG_OIDC_CLIENT_ID');
        $clientSecret = $this->readClusterSecretKey($kubectl, $ns, $secret, 'HEADLAMP_CONFIG_OIDC_CLIENT_SECRET');

        if (! $issuer || ! $clientId || ! $clientSecret) {
            return null;
        }

        return [
            'issuer' => $issuer,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    protected function resolveDashboardHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::DASHBOARD;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }
}
