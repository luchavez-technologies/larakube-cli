<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;

/** The vendor enum backing ClusterTool::FLOW — 'Workflow Automation'. */
enum FlowTool: string implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasSmtpWiring, HasVpnWiring
{
    public function getLabel(): string
    {
        return match ($this) {
            self::N8N => 'n8n',
            self::WINDMILL => 'Windmill',
        };
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'flow-vpn-only' : "flow-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return match ($this) {
            self::N8N => 'flow-n8n',
            self::WINDMILL => 'flow-windmill',
        };
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return match ($this) {
            self::N8N => [
                'deployment' => 'flow-n8n',
                'secret' => 'flow-n8n-smtp',
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
            ],
            // Windmill has no SMTP schema of its own yet (deliberately
            // deferred). Returning null for a KNOWN windmill engine is
            // correct — mail:wire on a Windmill-only install must refuse
            // rather than try to patch a nonexistent flow-n8n Deployment.
            self::WINDMILL => null,
        };
    }

    public function commonsDatabaseList(): array
    {
        return match ($this) {
            self::N8N => ['n8n'],
            self::WINDMILL => ['windmill'],
        };
    }
    case N8N = 'n8n';
    case WINDMILL = 'windmill';
}
