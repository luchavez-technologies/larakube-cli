<?php

use App\Data\WorkloadResourceProfile;
use App\Services\K8s\ManifestResourceParser;

function forgejoRenderedManifest(): string
{
    return view('k8s.git.forgejo', [
        'host' => 'git.luchtech.dev',
        'instance' => 'git-luchtech-dev',
        'tenant' => 'forgejo_git_luchtech_dev',
        'buckets' => ['forgejo-storage-git-luchtech-dev', 'forgejo-packages-git-luchtech-dev', 'forgejo-lfs-git-luchtech-dev'],
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        's3Host' => 'files.luchtech.dev',
        's3AccessKey' => 'ak',
        's3SecretKey' => 'sk',
        'forgejoVersion' => '16.0.4',
        'runnerVersion' => '13.1.0',
        'appName' => null,
    ])->render();
}

function n8nRenderedManifest(): string
{
    return view('k8s.flow.n8n', [
        'host' => 'flow.luchtech.dev',
        'engine' => 'n8n',
        'volumeSize' => fn (string $claim, string $default, bool $growth = false) => $default,
        'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => false,
    ])->render();
}

function monitoringRenderedManifest(): string
{
    return view('k8s.monitoring.shared', [
        'volumeSize' => fn (string $claim, string $default, bool $growth = false) => $default,
        'host' => 'monitor.luchtech.dev',
        'instance' => 'monitor-luchtech-dev',
        'appName' => null,
        'logoUrl' => '',
        'grafanaPassword' => 'grafana-secret',
        'noPlex' => false,
        'dbPassword' => 'db-secret',
        'plexNamespace' => 'larakube-plex',
        'isLocal' => false,
        'proxied' => false,
        'vpnOnly' => false,
        'withLogs' => true,
        'withTraces' => true,
    ])->render();
}

/** @return list<WorkloadResourceProfile> */
function parseManifest(string $rendered): array
{
    return (new ManifestResourceParser)->parse($rendered);
}

test('cpu quantity parsing handles millicores and bare cores', function (): void {
    expect(ManifestResourceParser::cpuQuantityToMillicores('500m'))->toBe(500)
        ->and(ManifestResourceParser::cpuQuantityToMillicores('1'))->toBe(1000)
        ->and(ManifestResourceParser::cpuQuantityToMillicores('0.5'))->toBe(500)
        ->and(ManifestResourceParser::cpuQuantityToMillicores('2'))->toBe(2000)
        ->and(ManifestResourceParser::cpuQuantityToMillicores('garbage'))->toBeNull();
});

test('memory quantity parsing handles binary and decimal suffixes, distinct from cpu', function (): void {
    // The same "m" suffix means something totally different for memory than
    // for CPU — "128m" as memory is nonsensical and must not silently parse.
    expect(ManifestResourceParser::memoryQuantityToBytes('512Mi'))->toBe(512 * 1024 ** 2)
        ->and(ManifestResourceParser::memoryQuantityToBytes('1Gi'))->toBe(1024 ** 3)
        ->and(ManifestResourceParser::memoryQuantityToBytes('1G'))->toBe(1000 ** 3)
        ->and(ManifestResourceParser::memoryQuantityToBytes('garbage'))->toBeNull();
});

test('forgejo manifest parses a declared resources block on its server Deployment', function (): void {
    $profiles = parseManifest(forgejoRenderedManifest());

    $server = collect($profiles)->first(fn (WorkloadResourceProfile $p) => str_contains($p->name, 'git-luchtech-dev') && $p->kind === 'Deployment');

    expect($server)->not->toBeNull();

    $container = collect($server->containers)->first(fn ($c) => $c->name === 'forgejo');

    expect($container)->not->toBeNull()
        ->and($container->declared)->toBeTrue()
        ->and($container->requestsCpuMillicores)->toBe(100)
        ->and($container->requestsMemoryBytes)->toBe(ManifestResourceParser::memoryQuantityToBytes('512Mi'))
        ->and($container->limitsCpuMillicores)->toBe(1000)
        ->and($container->limitsMemoryBytes)->toBe(ManifestResourceParser::memoryQuantityToBytes('1Gi'));
});

test('n8n manifest parses a declared resources block on its single container', function (): void {
    $profiles = parseManifest(n8nRenderedManifest());

    $deployment = collect($profiles)->first(fn (WorkloadResourceProfile $p) => $p->kind === 'Deployment');

    expect($deployment)->not->toBeNull()
        ->and($deployment->containers)->toHaveCount(1);

    $container = $deployment->containers[0];

    expect($container->name)->toBe('n8n')
        ->and($container->declared)->toBeTrue()
        ->and($container->requestsCpuMillicores)->toBe(100)
        ->and($container->requestsMemoryBytes)->toBe(ManifestResourceParser::memoryQuantityToBytes('512Mi'))
        ->and($container->limitsCpuMillicores)->toBe(1000)
        ->and($container->limitsMemoryBytes)->toBe(ManifestResourceParser::memoryQuantityToBytes('1Gi'))
        ->and($deployment->requestsCpuMillicoresPerReplica())->toBe(100)
        ->and($deployment->requestsMemoryBytesPerReplica())->toBe(ManifestResourceParser::memoryQuantityToBytes('512Mi'));
});

test('monitoring stack parses declared resources across all six components, including the promtail DaemonSet', function (): void {
    $profiles = parseManifest(monitoringRenderedManifest());

    $byContainer = [];
    foreach ($profiles as $profile) {
        foreach ($profile->containers as $container) {
            $byContainer[$container->name] = [$profile, $container];
        }
    }

    foreach (['prometheus', 'loki', 'tempo', 'promtail', 'kube-state-metrics', 'grafana'] as $name) {
        expect($byContainer)->toHaveKey($name);
        [, $container] = $byContainer[$name];
        expect($container->declared)->toBeTrue()
            ->and($container->requestsCpuMillicores)->not->toBeNull()
            ->and($container->requestsMemoryBytes)->not->toBeNull()
            ->and($container->limitsCpuMillicores)->not->toBeNull()
            ->and($container->limitsMemoryBytes)->not->toBeNull();
    }

    [$promtailWorkload] = $byContainer['promtail'];
    expect($promtailWorkload->kind)->toBe('DaemonSet')
        ->and($promtailWorkload->replicas)->toBe(1); // per-node; caller multiplies by live node count
});

test('an undeclared container falls back to the floor instead of counting as zero demand', function (): void {
    $rendered = <<<'YAML'
    apiVersion: apps/v1
    kind: Deployment
    metadata:
      name: example
      namespace: larakube-shared
    spec:
      replicas: 2
      template:
        spec:
          containers:
            - name: app
              image: example:1.0
    YAML;

    $profiles = parseManifest($rendered);

    expect($profiles)->toHaveCount(1);

    $profile = $profiles[0];
    expect($profile->kind)->toBe('Deployment')
        ->and($profile->replicas)->toBe(2)
        ->and($profile->containers[0]->declared)->toBeFalse()
        ->and($profile->containers[0]->requestsCpuMillicores)->toBeNull()
        ->and($profile->requestsCpuMillicoresPerReplica(undeclaredFloorMillicores: 50))->toBe(50)
        ->and($profile->requestsMemoryBytesPerReplica(undeclaredFloorBytes: 67108864))->toBe(67108864);
});

test('ignores non-workload documents and initContainers', function (): void {
    $rendered = <<<'YAML'
    apiVersion: v1
    kind: Service
    metadata:
      name: example
      namespace: larakube-shared
    spec:
      selector:
        app: example
    ---
    apiVersion: apps/v1
    kind: Deployment
    metadata:
      name: example
      namespace: larakube-shared
    spec:
      replicas: 1
      template:
        spec:
          initContainers:
            - name: migrate
              image: example:1.0
          containers:
            - name: app
              image: example:1.0
              resources:
                requests:
                  cpu: 50m
                  memory: 64Mi
    YAML;

    $profiles = parseManifest($rendered);

    expect($profiles)->toHaveCount(1)
        ->and($profiles[0]->kind)->toBe('Deployment')
        ->and($profiles[0]->containers)->toHaveCount(1)
        ->and($profiles[0]->containers[0]->name)->toBe('app');
});
