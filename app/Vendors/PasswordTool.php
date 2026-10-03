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
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
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
            // Same Secret tool:init --tool=vaultwarden already writes admin-token/plain-token
            // into — secrets:wire's dynamic ExternalSecret uses creationPolicy:
            // Merge, so this key rides alongside those without conflicting.
            // 'vaultwarden-secrets' never exists unless tool:init --tool=openbao's sweep
            // happens to create it, which left DATABASE_URL's hard (non-optional)
            // dependency unresolvable on any cluster without OpenBao bootstrapped.
            'secret' => 'vault-secrets',
            'key' => 'VAULTWARDEN_DATABASE_URL',
            // OpenBao's static-creds return the role's own name as `username`, and
            // a Commons tenant's role and database share one name, so the URL is
            // right for whichever instance the role belongs to.
            'template' => 'postgresql://{{ .username }}:{{ .password }}@postgres.larakube-plex.svc.cluster.local:5432/{{ .username }}',
        ];
    }

    /**
     * One PRIMARY component, with every resource vault/shared.blade.php
     * declares, so teardown() can't drift from what is deployed.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        // ClusterTool::components() strips the category from the Deployment
        // name; the nested resource names have to follow the same rule.
        // Composed here rather than read back from ToolInstance, which
        // derives every name FROM this list and would recurse.
        $canonical = fn (string $n) => ClusterTool::PASSWORDS->withoutCategory($n);
        $deployment = $canonical($name('passwords-vaultwarden'));

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('passwords-vaultwarden'),
                container: 'vaultwarden',
                resources: [
                    ['kind' => 'service', 'name' => $deployment],
                    ['kind' => 'ingress', 'name' => $deployment],
                    ['kind' => 'secret', 'name' => $canonical($name('passwords-vaultwarden-secrets'))],
                    ['kind' => 'secret', 'name' => $canonical($name('passwords-vaultwarden-oidc'))],
                    ['kind' => 'secret', 'name' => $canonical($name('passwords-vaultwarden-smtp'))],
                    ['kind' => 'pvc', 'name' => $canonical($name('passwords-vaultwarden-storage'))],
                ],
                backupVolume: true, backupPaths: ['/data'],
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::PASSWORDS->withoutCategory($n);

        return [
            'deployment' => $canonical($name('passwords-vaultwarden')),
            'secret' => $canonical($name('passwords-vaultwarden-smtp')),
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
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::PASSWORDS->withoutCategory($n);

        return [
            'deployment' => $canonical($name('passwords-vaultwarden')),
            'secret' => $canonical($name('passwords-vaultwarden-oidc')),
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

    public function openbaoSyncConfig(?string $instance = null): array
    {
        return [
            'secret' => ($instance === null || $instance === '')
                ? 'vaultwarden-secrets'
                : ToolInstance::forInstance(ClusterTool::PASSWORDS, $instance)->secret(),
            'keys' => ['VAULTWARDEN_DATABASE_URL'],
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['vaultwarden'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['vaultwarden'];
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, ?string $instance = null): array
    {
        $ns = ClusterTool::PASSWORDS->namespace();
        $secret = ($instance === null || $instance === '')
            ? 'vaultwarden-secrets'
            : ToolInstance::forInstance(ClusterTool::PASSWORDS, $instance)->secret();
        // The credentials Secret has no ADMIN_TOKEN key — admin-token holds the
        // Argon2id HASH Vaultwarden itself consumes (irreversible); the raw
        // token an operator actually logs in with is plain-token.
        $tokenVal = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} -o jsonpath='{.data.plain-token}' --ignore-not-found",
        )->output());
        $decodedToken = $tokenVal !== '' ? (base64_decode($tokenVal, true) ?: '<unknown>') : '<unknown>';

        return [
            ['Admin Token', $decodedToken],
            ['Admin Panel', $host ? "https://{$host}/admin" : '<unknown>'],
        ];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '')
            ? 'vault-vpn-only'
            : ToolInstance::forInstance(ClusterTool::PASSWORDS, $instance)->name('vpn-only');

        return [
            'name' => $name,
            'namespace' => ClusterTool::PASSWORDS->namespace(),
        ];
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        // By label: the Deployment is named per instance, so a bare name matches nothing.
        return 'deployment -l larakube.io/tool=passwords -n '.ClusterTool::PASSWORDS->namespace();
    }
}
