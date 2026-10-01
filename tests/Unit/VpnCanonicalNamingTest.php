<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ResourceNaming;
use App\Enums\SecretKind;
use Symfony\Component\Yaml\Yaml;

function vpnNames(): ToolInstance
{
    return ToolInstance::forHost(ClusterTool::VPN, 'vpn.example.com');
}

/** @return list<array{kind: string, name: string, labels: array<string, string>}> */
function vpnRenderedResources(): array
{
    $instance = vpnNames()->instance;

    $manifests = [
        view('k8s.vpn.shared', [
            'host' => 'vpn.example.com',
            'isLocal' => false,
            'noPlex' => false,
            'plexNamespace' => 'larakube-plex',
            'storeDb' => 'netbird_vpn_example_com',
            'ssoDomain' => 'example.com',
            'instance' => $instance,
        ])->render(),
        view('k8s.vpn.client', ['instance' => $instance])->render(),
        view('k8s.vpn.resolver-config', [
            'hosts' => ['admin.example.com'],
            'gatewayIp' => '100.84.155.135',
            'instance' => $instance,
        ])->render(),
    ];

    $resources = [];
    foreach ($manifests as $manifest) {
        foreach (preg_split('/^---$/m', $manifest) as $document) {
            $parsed = trim($document) === '' ? null : Yaml::parse($document);
            if (is_array($parsed) && isset($parsed['kind'])) {
                $resources[] = [
                    'kind' => $parsed['kind'],
                    'name' => $parsed['metadata']['name'],
                    'labels' => $parsed['metadata']['labels'] ?? [],
                ];
            }
        }
    }

    return $resources;
}

test('vpn is on canonical naming', function (): void {
    expect(ClusterTool::VPN->resourceNaming())->toBe(ResourceNaming::CANONICAL);
});

test('every name the vpn manifests write is a name ToolInstance hands out', function (): void {
    // The failure this guards against is silent in both directions: a
    // manifest that writes a name the enum does not know leaves teardown,
    // backup discovery and the OpenBao sync pointing at nothing, and an enum
    // name no manifest creates makes a Merge-policy ExternalSecret sync into
    // a Secret that will never exist.
    $names = vpnNames();

    $known = [
        $names->deployment(),
        $names->deployment('signal'),
        $names->deployment('relay'),
        $names->deployment('dashboard'),
        $names->deployment('client'),
        $names->volume('storage'),
        $names->volume('storage', 'client'),
        $names->configMap('resolver', 'client'),
        $names->secret(),
        $names->secret(SecretKind::STORE),
        $names->secret(SecretKind::CONFIG),
        $names->secret(SecretKind::OIDC),
    ];

    $rendered = array_values(array_unique(array_column(vpnRenderedResources(), 'name')));

    expect(array_diff($rendered, $known))->toBeEmpty(
        'rendered names the enum does not know: '.implode(', ', array_diff($rendered, $known)),
    );
});

test('the vpn stem is the product, and the category is gone from every name', function (): void {
    // `netbird-*`, not `management-*`/`signal-*`: the stem says what the thing
    // is, the way grafana/forgejo/ocis/outline do. And not `vpn-*`: the
    // category was redundant with the instance (ADR 0021).
    foreach (vpnRenderedResources() as $resource) {
        expect($resource['name'])
            ->toStartWith('netbird')
            ->toEndWith('-vpn-example-com');
    }
});

test('every vpn resource carries the identity labels discovery selects on', function (): void {
    // Dropping the category makes component names collide with upstream's own
    // Deployments, so identity has to live in the labels rather than the name
    // — SharedClusterService::VPN's presence probe already selects on them.
    foreach (vpnRenderedResources() as $resource) {
        expect($resource['labels'])
            ->toHaveKey('larakube.io/tool', 'vpn')
            ->toHaveKey('larakube.io/instance', 'vpn-example-com')
            ->toHaveKey('larakube.io/managed-by', 'larakube')
            ->toHaveKey('larakube.io/component');
    }
});

test('the commons tenant is named after the deployment that owns it', function (): void {
    // `netbird-…` ↔ `netbird_…`. The rule that keeps a purge from dropping
    // nothing: netbird:init and vpn:remove --purge both derive the name here.
    expect(ClusterTool::VPN->commonsDatabases(vpnNames()->instance))
        ->toBe(['netbird_vpn_example_com']);
});

test('the database password lives in its own Secret, not the credentials one', function (): void {
    // secrets:wire's ExternalSecret owns every key in the Secret it targets,
    // so collapsing the two would let a database rotation clobber the PAT,
    // setup key and dashboard login stored alongside.
    $names = vpnNames();

    expect(ClusterTool::VPN->dbSecretRef($names->instance)['secret'])
        ->toBe($names->secret(SecretKind::STORE))
        ->not->toBe($names->secret());
});
