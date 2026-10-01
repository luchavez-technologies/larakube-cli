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
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
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
        $name = ($instance === null || $instance === '') ? 'openbao-vpn-only' : "openbao-vpn-only-{$instance}";

        return [
            'name' => $name,
            // The ingress annotation is larakube-secrets-openbao-vpn-only-{instance}@kubernetescrd —
            // SECRETS' own namespace, not larakube-shared.
            'namespace' => 'larakube-secrets',
        ];
    }

    /**
     * One PRIMARY component with every resource openbao.blade.php declares, so
     * teardown() can't drift from what is deployed. The category is stripped from
     * the Deployment name by ClusterTool::components(); the nested names are
     * composed here the same way, rather than read back from ToolInstance, which
     * derives every name FROM this list and would recurse.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::SECRETS->withoutCategory($n);
        $deployment = $canonical($name('secrets-openbao'));

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('secrets-openbao'),
                container: 'openbao',
                resources: [
                    ['kind' => 'service', 'name' => $deployment],
                    ['kind' => 'ingress', 'name' => $deployment],
                    ['kind' => 'configmap', 'name' => $canonical($name('secrets-openbao-config'))],
                    ['kind' => 'secret', 'name' => $canonical($name('secrets-openbao-secrets'))],
                    ['kind' => 'secret', 'name' => $canonical($name('secrets-openbao-oidc'))],
                    ['kind' => 'pvc', 'name' => $canonical($name('secrets-openbao-storage'))],
                    ['kind' => 'serviceaccount', 'name' => $deployment],
                    ['kind' => 'clusterrolebinding', 'name' => $canonical($name('secrets-openbao-auth-delegator'))],
                ],
                backupVolume: true, backupPaths: ['/openbao'],
            ),
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::SECRETS->withoutCategory($n);

        return [
            'deployment' => $canonical($name('secrets-openbao')),
            'secret' => $canonical($name('secrets-openbao-oidc')),
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

    public function canonicalDatabaseList(): array
    {
        return [];
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, ?string $instance = null): array
    {
        $ns = ClusterTool::SECRETS->namespace();
        $secretName = ($instance === null || $instance === '')
            ? 'openbao-secrets'
            : ToolInstance::forInstance(ClusterTool::SECRETS, $instance)->secret();
        $tokenVal = trim(Process::run(
            "{$kubectl} get secret {$secretName} -n {$ns} -o jsonpath='{.data.root-token}' --ignore-not-found",
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
        // By label: the Deployment is named per instance, so a bare name matches nothing.
        return 'deployment -l larakube.io/tool=secrets -n larakube-secrets';
    }
}
