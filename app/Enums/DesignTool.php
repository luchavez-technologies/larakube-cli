<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasBaselineFlags;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;

/** The vendor enum backing ClusterTool::DESIGN — 'Design & Prototyping'. Only Penpot today. */
enum DesignTool: string implements ClusterToolVendor, HasBaselineFlags, HasCommonsBuckets, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasOidcWiring, HasOpenbaoSync, HasRotatableDatabasePassword, HasSmtpWiring, HasVpnWiring, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Penpot';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'design-vpn-only' : "design-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'backend',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('design-penpot-backend'),
                resources: [
                    ['kind' => 'service', 'name' => 'design-backend'],
                    ['kind' => 'secret', 'name' => 'design-secrets'],
                    ['kind' => 'secret', 'name' => 'design-smtp'],
                    ['kind' => 'secret', 'name' => 'design-oidc'],
                ],
            ),
            new ClusterToolComponentData(
                key: 'frontend',
                role: ClusterToolComponentRole::INGRESS,
                deployment: $name('design-penpot-frontend'),
                sharesPrimarySecret: true,
                resources: [
                    ['kind' => 'service', 'name' => 'design'],
                    ['kind' => 'ingress', 'name' => 'design'],
                ],
            ),
            new ClusterToolComponentData(
                key: 'exporter',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('design-penpot-exporter'),
                resources: [
                    ['kind' => 'service', 'name' => 'design-exporter'],
                ],
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        $suffix = ($instance === null || $instance === '') ? '' : "-{$instance}";

        return [
            'deployment' => "design-penpot-backend{$suffix}",
            'secret' => "design-smtp{$suffix}",
            'static' => [
                // PENPOT_FLAGS is deliberately absent — MailWireCommand
                // reconciles it via ReconcilesPenpotFlags instead of the
                // generic static-var path. See
                // docs/decisions/0013-design-init-idempotent-flags.md.
                'PENPOT_SMTP_SSL' => 'true',
                'PENPOT_SMTP_TLS' => 'true',
            ],
            'vars' => [
                'host' => 'PENPOT_SMTP_HOST',
                'port' => 'PENPOT_SMTP_PORT',
                'user' => 'PENPOT_SMTP_USERNAME',
                'password' => 'PENPOT_SMTP_PASSWORD',
                'from' => 'PENPOT_SMTP_DEFAULT_FROM',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        $suffix = ($instance === null || $instance === '') ? '' : "-{$instance}";

        return [
            'deployment' => "design-penpot-backend{$suffix}",
            'secret' => "design-oidc{$suffix}",
            'redirect_path' => '/api/auth/oidc/callback',
            'static' => [
                // PENPOT_FLAGS is deliberately absent — SsoWireCommand::applyToolEnv
                // reconciles it via ReconcilesPenpotFlags instead of the generic
                // static-var path. See docs/decisions/0013-design-init-idempotent-flags.md.
                'PENPOT_OIDC_NAME' => 'Login with SSO',
            ],
            'vars' => [
                'client_id' => 'PENPOT_OIDC_CLIENT_ID',
                'client_secret' => 'PENPOT_OIDC_CLIENT_SECRET',
                'auth_url' => 'PENPOT_OIDC_AUTH_URI',
                'token_url' => 'PENPOT_OIDC_TOKEN_URI',
                'userinfo_url' => 'PENPOT_OIDC_USERINFO_URI',
                'issuer' => 'PENPOT_OIDC_BASE_URI',
            ],
        ];
    }

    /** Not 'db-password' like every other tool — Penpot's own secret already established 'password'. */
    public function dbSecretRef(): ?array
    {
        return ['secret' => 'design-secrets', 'key' => 'password'];
    }

    public function commonsDatabaseList(): array
    {
        return ['penpot'];
    }

    public function commonsBucketList(): array
    {
        return ['design-assets'];
    }

    /**
     * Penpot's backend/exporter use the Commons Valkey for pub/sub and
     * WebSocket session coordination (not primary data — that's Postgres),
     * via a dedicated logical DB index allocated through
     * allocateCommonsRedisIndex(), same as GIT/NOTES/SHEETS.
     */
    public function commonsRedisKeys(): array
    {
        return ['penpot'];
    }

    public function baselineFlags(): array
    {
        // `enable-mcp` deliberately excluded: Penpot's frontend image bakes
        // in an nginx location block that proxies to an upstream literally
        // named `penpot-mcp` — a 4th first-party MCP backend container we
        // don't deploy. Without it nginx fails to start at all, crash-looping
        // the ENTIRE frontend, not just MCP. Confirmed live 2026-08-10 —
        // took down design.luchtech.dev. Re-add only once that companion
        // container is actually deployed alongside backend/frontend/exporter.
        return ['enable-access-tokens'];
    }

    public function openbaoSyncConfig(?string $instance = null): array
    {
        return [
            'secret' => 'design-secrets',
            'keys' => ['DESIGN_DB_PASSWORD'],
        ];
    }
    case PENPOT = 'penpot';
}
