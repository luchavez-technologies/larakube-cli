<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;

/**
 * The vendor enum backing ClusterTool::TASKS — 'Project Management'.
 * Plane is dead (zero references anywhere outside the legacy engines()
 * list) and is deliberately dropped here — only Planka ships.
 */
enum TaskTool: string implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasOidcWiring, HasSmtpWiring, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Planka';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'tasks-vpn-only' : "tasks-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'tasks-planka';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'tasks-planka',
            'secret' => 'tasks-planka-smtp',
            'static' => [
                'SMTP_SECURE' => 'true',
            ],
            'vars' => [
                'host' => 'SMTP_HOST',
                'port' => 'SMTP_PORT',
                'user' => 'SMTP_USER',
                'password' => 'SMTP_PASSWORD',
                'from' => 'SMTP_FROM',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'tasks-planka',
            'secret' => 'tasks-planka-oidc',
            'static' => [
                'OIDC_NAME' => 'Login with SSO',
            ],
            'sso_only_vars' => [
                'ALLOW_REGISTRATION' => 'false',
            ],
            'vars' => [
                'client_id' => 'OIDC_CLIENT_ID',
                'client_secret' => 'OIDC_CLIENT_SECRET',
                'issuer' => 'OIDC_ISSUER',
            ],
            'redirect_path' => '/oidc-callback',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['tasks_planka'];
    }
    case PLANKA = 'planka';
}
