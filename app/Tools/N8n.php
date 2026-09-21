<?php

namespace App\Tools;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasImages;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Tools\Concerns\PinsImages;

/**
 * n8n, one of the FLOW engines. SSO is an n8n Enterprise feature, so it has
 * no oidcEnv(); owners sign in with n8n's own accounts.
 */
final class N8n implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasImages, HasSmtpWiring, HasVpnWiring
{
    use PinsImages;

    public const string ENGINE = 'n8n';

    public function getLabel(): string
    {
        return 'n8n';
    }

    public function images(): array
    {
        return ['n8n' => 'docker.n8n.io/n8nio/n8n:2.39.8'];
    }

    public function baseDeploymentName(): string
    {
        return 'flow-n8n';
    }

    public function canonicalComponentName(): string
    {
        return 'n8n';
    }

    public function commonsDatabaseList(): array
    {
        return ['n8n'];
    }

    /** Shared by both engines: an instance is a host, whichever engine serves it. */
    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        return [
            'name' => ($instance === null || $instance === '') ? 'flow-vpn-only' : "flow-vpn-only-{$instance}",
            'namespace' => ClusterTool::FLOW->namespace(),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        $names = ($instance === null || $instance === '') ? null : ToolInstance::forInstance(ClusterTool::FLOW, $instance, self::ENGINE);
        // Without an instance there is no ToolInstance to ask, but the name
        // still has to follow the tool's current naming generation — reading
        // baseDeploymentName() directly would pin it to the pre-migration one.
        $base = ClusterTool::FLOW->deploymentName(engine: self::ENGINE);

        return [
            'deployment' => $names?->deployment() ?? $base,
            'secret' => $names?->secret(SecretKind::SMTP) ?? $base.'-'.SecretKind::SMTP->value,
            'static' => [
                'N8N_EMAIL_MODE' => 'smtp',
                'N8N_SMTP_SSL' => 'true',
                'N8N_SMTP_STARTTLS' => 'false',
            ],
            'vars' => [
                'host' => 'N8N_SMTP_HOST',
                'port' => 'N8N_SMTP_PORT',
                'user' => 'N8N_SMTP_USER',
                'password' => 'N8N_SMTP_PASS',
                'from' => 'N8N_SMTP_SENDER',
            ],
        ];
    }
}
