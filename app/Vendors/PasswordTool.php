<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasPresenceProbe;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;
use Illuminate\Support\Facades\Process;

/** The single vendor backing the PASSWORDS category — 'Password Manager'. Only Vaultwarden. */
final class PasswordTool implements ClusterToolVendor, HasCommonsDatabases, HasDbSecretRef, HasOidcWiring, HasOpenbaoSync, HasPresenceProbe, HasRotatableDatabasePassword, HasSmtpWiring, HasToolAccessDetails, HasVpnWiring, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Vaultwarden';
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'vaultwarden-secrets',
            'key' => 'VAULTWARDEN_DATABASE_URL',
            'template' => 'postgresql://vaultwarden:{{ .password }}@postgres.larakube-plex.svc.cluster.local:5432/vaultwarden',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '' || $instance === 'main') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('vaultwarden'),
                container: 'vaultwarden', backupVolume: true, backupPath: '/data',
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'vaultwarden',
            'secret' => 'vaultwarden-smtp',
            'static' => [
                'SMTP_SECURITY' => 'force_tls',
            ],
            'vars' => [
                'host' => 'SMTP_HOST',
                'port' => 'SMTP_PORT',
                'user' => 'SMTP_USERNAME',
                'password' => 'SMTP_PASSWORD',
                'from' => 'SMTP_FROM',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'vaultwarden',
            'secret' => 'vaultwarden-oidc',
            'static' => [
                'SSO_ENABLED' => 'true',
                'SSO_PKCE' => 'true',
                'SSO_SCOPES' => 'email profile',
                'SSO_SIGNUPS_MATCH_EMAIL' => 'true',
                // Zitadel includes extra audiences (project id, etc.) in the
                // id_token beyond the client_id. Vaultwarden trusts only the
                // client_id by default and rejects the rest ("not a trusted
                // audience"). Trust any Zitadel numeric id — issuer + token
                // signature are still validated, so this is safe.
                'SSO_AUDIENCE_TRUSTED' => '^[0-9]+$',
            ],
            'sso_only_vars' => [
                'SIGNUPS_ALLOWED' => 'false',
            ],
            'vars' => [
                'client_id' => 'SSO_CLIENT_ID',
                'client_secret' => 'SSO_CLIENT_SECRET',
                // Vaultwarden's SSO_AUTHORITY is the OIDC issuer (its own
                // .well-known/openid-configuration is discovered from this),
                // not a raw host — Zitadel's issuer IS its external host.
                'issuer' => 'SSO_AUTHORITY',
            ],
            'redirect_path' => '/identity/connect/oidc-signin',
        ];
    }

    public function openbaoSyncConfig(): array
    {
        return [
            'secret' => 'vaultwarden-secrets',
            'keys' => ['VAULTWARDEN_DATABASE_URL'],
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['vaultwarden'];
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, string $instance = 'main'): array
    {
        $ns = ($instance === 'main' || $instance === null || $instance === '') ? 'larakube-vault' : "larakube-vault-{$instance}";
        $tokenVal = trim(Process::run(
            "{$kubectl} get secret vault-admin -n {$ns} -o jsonpath='{.data.ADMIN_TOKEN}' --ignore-not-found",
        )->output());
        $decodedToken = $tokenVal !== '' ? (base64_decode($tokenVal, true) ?: '<unknown>') : '<unknown>';

        return [
            ['Admin Token', $decodedToken],
            ['Admin Panel', $host ? "https://{$host}/admin" : '<unknown>'],
        ];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'vault-vpn-only' : "vault-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-vault',
        ];
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $deployment = ($instance === null || $instance === '' || $instance === 'main') ? 'vaultwarden' : "vaultwarden-{$instance}";

        return "deployment/{$deployment} -n larakube-vault";
    }
}
