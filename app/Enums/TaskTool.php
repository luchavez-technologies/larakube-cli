<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Data\ToolInstance;

/**
 * The vendor enum backing ClusterTool::TASKS — 'Project Management'.
 * Plane is dead (zero references anywhere outside the legacy engines()
 * list) and is deliberately dropped here — only Planka ships.
 *
 * No HasOidcWiring: Planka's OIDC/SSO was removed from the Community
 * (OSS) edition in v2.2.0 and moved to PLANKA Pro (paid) — confirmed
 * against the real v2.1.1 vs v2.2.1 docker-compose.yml/server source,
 * not just their marketing docs, which still describe it as current
 * (docs.planka.cloud is run by PLANKA Software GmbH and documents the
 * Pro product). Deliberately not wired at all, rather than caveated
 * like Directus's paid SSO — Directus's wiring is real and works with a
 * license; there is no OIDC code left in the OSS image to wire into.
 */
enum TaskTool: string implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasRotatableDatabasePassword, HasSmtpWiring, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Planka';
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'planka-secrets', 'key' => 'db-password'];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '')
            ? 'planka-vpn-only'
            : ToolInstance::forInstance(ClusterTool::TASKS, $instance, $this->value)->name('vpn-only');

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'tasks-planka';
    }

    public function canonicalComponentName(): string
    {
        return 'planka';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => ClusterTool::TASKS->deploymentName($instance, $this->value),
            'secret' => $this->name($instance, SecretKind::SMTP->value),
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

    public function commonsDatabaseList(): array
    {
        return ['tasks_planka'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['planka'];
    }

    private function name(?string $instance, string $token): string
    {
        return ($instance === null || $instance === '')
            ? "planka-{$token}"
            : ToolInstance::forInstance(ClusterTool::TASKS, $instance, $this->value)->name($token);
    }

    case PLANKA = 'planka';
}
