<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasPresenceProbe;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;
use Illuminate\Support\Facades\Process;

/** The single vendor backing the SECRETS category — 'Secrets Manager'. Only OpenBao. */
final class SecretTool implements ClusterToolVendor, HasCommonsDatabases, HasOidcWiring, HasPresenceProbe, HasToolAccessDetails, HasVpnWiring, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'OpenBao';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'openbao-vpn-only' : "openbao-vpn-only-{$instance}";

        return [
            'name' => $name,
            // The ingress annotation is larakube-secrets-openbao-vpn-only@kubernetescrd —
            // SECRETS' own namespace, not larakube-shared.
            'namespace' => 'larakube-secrets',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '' || $instance === 'main') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('openbao-backend'),
                container: 'openbao', backupVolume: true, backupPath: '/openbao',
            ),
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'openbao-backend',
            'secret' => 'openbao-oidc',
            'static' => [],
            'vars' => [],
            'redirect_path' => '/v1/auth/oidc/oidc/callback',
        ];
    }

    /** No Commons tenant of its own — OpenBao stores secrets, not application data. Explicit [], not an omitted interface, per the 2026-08 SSO/Commons audit. */
    public function commonsDatabaseList(): array
    {
        return [];
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, string $instance = 'main'): array
    {
        $ns = ($instance === 'main' || $instance === null || $instance === '') ? 'larakube-secrets' : "larakube-secrets-{$instance}";
        $tokenVal = trim(Process::run(
            "{$kubectl} get secret openbao-bootstrap -n {$ns} -o jsonpath='{.data.root-token}' --ignore-not-found",
        )->output());
        $decodedToken = $tokenVal !== '' ? (base64_decode($tokenVal, true) ?: '<unknown>') : null;

        $rows = [
            ['Secrets Engine', 'OpenBao (KV v2)'],
        ];
        if ($decodedToken !== null) {
            $rows[] = ['Root Token', $decodedToken];
        }

        return $rows;
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $deployment = ($instance === null || $instance === '' || $instance === 'main') ? 'openbao-backend' : "openbao-backend-{$instance}";

        return "deployment/{$deployment} -n larakube-secrets";
    }
}
