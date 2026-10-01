<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use Symfony\Component\Yaml\Yaml;

/** @return Illuminate\Support\Collection<int, array<string, mixed>> */
function monitorDocuments(array $overrides = []): Illuminate\Support\Collection
{
    $rendered = view('k8s.monitoring.shared', array_merge([
        'host' => 'grafana.example.com',
        'instance' => 'monitor-example-com',
        'grafanaPassword' => 'secret123',
        'dbPassword' => 'db-secret123',
        'plexNamespace' => 'larakube-plex',
        'isLocal' => true,
        'vpnOnly' => false,
        'withLogs' => true,
        'withTraces' => true,
        'volumeSize' => fn (string $claim, string $default): string => $default,
    ], $overrides))->render();

    return collect(array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $rendered)), fn (string $doc) => $doc !== '')),
    ))->filter(fn ($doc) => is_array($doc) && isset($doc['kind']));
}

test('every object the monitoring stack deploys carries the instance in its name', function (): void {
    $documents = monitorDocuments();

    $bare = $documents
        ->filter(fn (array $doc) => ! str_ends_with((string) $doc['metadata']['name'], 'monitor-example-com'))
        ->map(fn (array $doc) => $doc['kind'].'/'.$doc['metadata']['name'])
        ->values()
        ->all();

    expect($bare)->toBe([]);
});

test('the stack names its ServiceAccounts, RBAC and ConfigMaps from ToolInstance', function (): void {
    $names = ToolInstance::forInstance(ClusterTool::MONITOR, 'monitor-example-com');
    $byKind = fn (string $kind) => monitorDocuments()->where('kind', $kind)->pluck('metadata.name')->all();

    expect($byKind('ServiceAccount'))->toEqualCanonicalizing([
        'prometheus-monitor-example-com', 'promtail-monitor-example-com', 'kube-state-metrics-monitor-example-com',
    ])
        ->and($byKind('ClusterRole'))->toEqualCanonicalizing([
            'prometheus-role-monitor-example-com', 'promtail-role-monitor-example-com', 'kube-state-metrics-role-monitor-example-com',
        ])
        ->and($byKind('ClusterRoleBinding'))->toEqualCanonicalizing($byKind('ClusterRole'))
        ->and($byKind('ConfigMap'))->toContain(
            $names->configMap('datasources', 'grafana'),
            $names->configMap('dashboard-provider', 'grafana'),
            $names->configMap('config', 'tempo'),
        )
        ->and($names->configMap('datasources', 'grafana'))->toBe('grafana-datasources-monitor-example-com')
        ->and($names->configMap('dashboards', 'grafana'))->toBe('grafana-dashboards-monitor-example-com')
        ->and($byKind('Deployment'))->toContain('tempo-monitor-example-com', 'kube-state-metrics-monitor-example-com');
});

test('every binding, mount and Pod reference follows the renamed object', function (): void {
    $documents = monitorDocuments();
    $deployment = fn (string $name) => $documents->first(fn ($d) => $d['kind'] === 'Deployment' && $d['metadata']['name'] === $name);
    $volumes = fn (array $d) => collect($d['spec']['template']['spec']['volumes'] ?? [])->keyBy('name');
    $binding = $documents->firstWhere(fn ($d) => $d['kind'] === 'ClusterRoleBinding' && $d['metadata']['name'] === 'prometheus-role-monitor-example-com');
    $grafana = $deployment('grafana-monitor-example-com');
    $mounted = $volumes($grafana)->map(fn ($v) => $v['configMap']['name'] ?? null)->filter()->values()->all();

    expect($deployment('prometheus-monitor-example-com')['spec']['template']['spec']['serviceAccountName'])->toBe('prometheus-monitor-example-com')
        ->and($deployment('kube-state-metrics-monitor-example-com')['spec']['template']['spec']['serviceAccountName'])->toBe('kube-state-metrics-monitor-example-com')
        ->and($documents->firstWhere('kind', 'DaemonSet')['spec']['template']['spec']['serviceAccountName'])->toBe('promtail-monitor-example-com')
        ->and($binding['roleRef']['name'])->toBe('prometheus-role-monitor-example-com')
        ->and($binding['subjects'][0]['name'])->toBe('prometheus-monitor-example-com')
        ->and($mounted)->toEqualCanonicalizing([
            'grafana-datasources-monitor-example-com',
            'grafana-dashboard-provider-monitor-example-com',
            'grafana-dashboards-monitor-example-com',
        ]);
});

test('Prometheus scrapes kube-state-metrics and Grafana reads Tempo on their renamed Services', function (): void {
    $documents = monitorDocuments();
    $config = $documents->firstWhere(fn ($d) => $d['kind'] === 'ConfigMap' && $d['metadata']['name'] === 'prometheus-config-monitor-example-com');
    $datasources = $documents->firstWhere(fn ($d) => $d['kind'] === 'ConfigMap' && $d['metadata']['name'] === 'grafana-datasources-monitor-example-com');
    $services = $documents->where('kind', 'Service')->pluck('metadata.name')->all();

    expect($config['data']['prometheus.yml'])->toContain('kube-state-metrics-monitor-example-com.larakube-shared.svc.cluster.local:8080')
        ->and($datasources['data']['datasources.yaml'])->toContain('http://tempo-monitor-example-com.larakube-shared.svc.cluster.local:3200')
        ->and($services)->toContain('kube-state-metrics-monitor-example-com', 'tempo-monitor-example-com');
});

test('every component of the stack carries the identity labels', function (): void {
    $documents = monitorDocuments();

    foreach (['ServiceAccount', 'ClusterRole', 'Deployment', 'Service', 'DaemonSet'] as $kind) {
        foreach ($documents->where('kind', $kind) as $doc) {
            expect($doc['metadata']['labels']['larakube.io/instance'] ?? null)->toBe('monitor-example-com', "{$kind}/{$doc['metadata']['name']}");
        }
    }
});
