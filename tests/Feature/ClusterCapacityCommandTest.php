<?php

use App\Enums\ClusterTool;
use App\Services\K8s\ClusterCapacityAssessor;
use App\Support\Labels;
use Illuminate\Support\Facades\Process;

function capacityFakes(
    string $nodeCpu = '2',
    string $nodeMemory = '2Gi',
    string $nodeUsedMemory = '600Mi',
    array $pods = [],
    array $podMetrics = [],
    array $deployments = [],
): array {
    return [
        '*get nodes -o json*' => Process::result(output: json_encode(['items' => [
            ['status' => ['allocatable' => ['cpu' => $nodeCpu, 'memory' => $nodeMemory]]],
        ]])),
        '*get pods -A -o json*' => Process::result(output: json_encode(['items' => $pods])),
        '*apis/metrics.k8s.io/v1beta1/nodes*' => Process::result(output: json_encode(['items' => [
            ['usage' => ['cpu' => '250m', 'memory' => $nodeUsedMemory]],
        ]])),
        '*apis/metrics.k8s.io/v1beta1/pods*' => Process::result(output: json_encode(['items' => $podMetrics])),
        '*get deployments -A*' => Process::result(output: json_encode(['items' => $deployments])),
    ];
}

function fakePod(string $ns, string $name, string $cpu, string $mem, array $labels = []): array
{
    return [
        'metadata' => ['namespace' => $ns, 'name' => $name, 'labels' => $labels],
        'status' => ['phase' => 'Running'],
        'spec' => [
            'containers' => [[
                'name' => $name,
                'resources' => ['requests' => ['cpu' => $cpu, 'memory' => $mem]],
            ]],
        ],
    ];
}

test('cluster:capacity --json breaks usage into system, platform, apps, and free', function (): void {
    Process::fake(capacityFakes(
        nodeCpu: '2',
        nodeMemory: '2Gi',
        nodeUsedMemory: '800Mi',
        pods: [
            fakePod('traefik', 'traefik-1', '50m', '64Mi'),
            fakePod('larakube-shared', 'pocketbase-1', '50m', '64Mi', [
                Labels::TOOL => 'pocketbase',
                Labels::INSTANCE => 'pb-example-com',
            ]),
        ],
        podMetrics: [
            ['metadata' => ['namespace' => 'traefik', 'name' => 'traefik-1'], 'containers' => [['usage' => ['cpu' => '10m', 'memory' => '64Mi']]]],
            ['metadata' => ['namespace' => 'larakube-shared', 'name' => 'pocketbase-1'], 'containers' => [['usage' => ['cpu' => '10m', 'memory' => '64Mi']]]],
        ],
    ));

    $this->withoutMockingConsoleOutput();
    $exit = Illuminate\Support\Facades\Artisan::call('cluster:capacity', ['--context' => 'ctx', '--json' => true]);
    $output = json_decode(Illuminate\Support\Facades\Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($output['node']['basis'])->toBe('usage')
        ->and($output['breakdown']['apps'][0]['tool'])->toBe('pocketbase');
});

test('ClusterCapacityAssessor returns fits, tight, wont_fit_now, and never_fits verdicts accurately', function (): void {
    // 1. fits: PocketBase (~64Mi) on 2Gi node with 600Mi used -> plenty of free RAM.
    Process::fake(capacityFakes(nodeCpu: '2', nodeMemory: '2Gi', nodeUsedMemory: '600Mi'));
    $fits = (new ClusterCapacityAssessor)->assess('kubectl --context=ctx', ClusterTool::POCKETBASE);
    expect($fits['verdict'])->toBe('fits');

    // 2. tight: 1Gi usable (~922Mi), 800Mi used (122Mi free), PocketBase leaves <15% of 922Mi (138Mi).
    Process::fake(capacityFakes(nodeCpu: '2', nodeMemory: '1Gi', nodeUsedMemory: '800Mi'));
    $tight = (new ClusterCapacityAssessor)->assess('kubectl --context=ctx', ClusterTool::POCKETBASE);
    expect($tight['verdict'])->toBe('tight');

    // 3. wont_fit_now: 2Gi usable (~1843Mi), system=200Mi, Directus (~256Mi+) vs running n8n (1400Mi) -> pausing n8n frees room!
    Process::fake(capacityFakes(
        nodeCpu: '4',
        nodeMemory: '2Gi',
        nodeUsedMemory: '1600Mi',
        pods: [
            fakePod('larakube-shared', 'n8n-flow', '500m', '1400Mi', [
                Labels::TOOL => 'n8n',
                Labels::INSTANCE => 'flow-example-com',
            ]),
        ],
        podMetrics: [
            ['metadata' => ['namespace' => 'larakube-shared', 'name' => 'n8n-flow'], 'containers' => [['usage' => ['cpu' => '100m', 'memory' => '1400Mi']]]],
        ],
    ));
    $wontFitNow = (new ClusterCapacityAssessor)->assess('kubectl --context=ctx', ClusterTool::DIRECTUS);
    expect($wontFitNow['verdict'])->toBe('wont_fit_now')
        ->and($wontFitNow['pause_candidates'][0]['tool'])->toBe('n8n')
        ->and($wontFitNow['alternatives_that_fit'][0]['tool'])->toBe('pocketbase');

    // 4. never_fits: Grafana (~1.1Gi) on a 1Gi node.
    Process::fake(capacityFakes(nodeCpu: '2', nodeMemory: '1Gi', nodeUsedMemory: '400Mi'));
    $neverFits = (new ClusterCapacityAssessor)->assess('kubectl --context=ctx', ClusterTool::GRAFANA);
    expect($neverFits['verdict'])->toBe('never_fits');
});

test('re-applying an app counts only what it adds on top of what already runs', function (bool $alreadyRunning, string $verdict): void {
    $deployment = [
        'apiVersion' => 'apps/v1',
        'kind' => 'Deployment',
        'metadata' => ['name' => 'grafana-monitor-example-com', 'namespace' => 'larakube-shared'],
        'spec' => ['replicas' => 1, 'template' => ['spec' => ['containers' => [[
            'name' => 'grafana',
            'resources' => ['requests' => ['cpu' => '200m', 'memory' => '900Mi']],
        ]]]]],
    ];

    // A 1Gi node already busy with that very Deployment.
    Process::fake([
        '*get deployment grafana-monitor-example-com -n larakube-shared*' => Process::result(output: $alreadyRunning ? json_encode($deployment) : ''),
        ...capacityFakes(nodeCpu: '2', nodeMemory: '1Gi', nodeUsedMemory: '950Mi', pods: [
            fakePod('larakube-shared', 'grafana-monitor-example-com-abc', '200m', '900Mi', [Labels::TOOL => 'grafana']),
        ]),
    ]);

    $assessment = (new ClusterCapacityAssessor)->assess('kubectl --context=ctx', ClusterTool::GRAFANA, renderedManifest: json_encode($deployment));

    expect($assessment['verdict'])->toBe($verdict);
})->with([
    'an unchanged re-apply adds nothing, so it fits' => [true, 'fits'],
    'the same manifest with nothing running is new demand' => [false, 'never_fits'],
]);

test('an app with buckets on a cluster with no Commons object store also counts the SeaweedFS its install creates', function (?array $commons, array $options, bool $counted): void {
    Process::fake([
        '*get configmap plex-commons*' => Process::result(output: $commons === null ? '' : json_encode($commons)),
        ...capacityFakes(nodeCpu: '4', nodeMemory: '8Gi', nodeUsedMemory: '1Gi'),
    ]);

    $assessment = (new ClusterCapacityAssessor)->assess('kubectl --context=ctx', ClusterTool::SUPABASE, $options);
    $commonsParts = array_filter($assessment['demand']['components'], fn (array $c): bool => str_starts_with($c['name'], 'Plex Commons '));
    $app = (new App\Services\K8s\ToolFootprint)->measure(ClusterTool::SUPABASE, $options);

    expect($commonsParts !== [])->toBe($counted)
        ->and($assessment['demand']['memory_bytes'])->toBe($app['memory_bytes'] + ($counted ? 256 * 1024 * 1024 : 0));
})->with([
    'no Commons yet' => [null, [], true],
    'Commons without an object store' => [['services' => ['postgres' => ['enabled' => true]]], [], true],
    'Commons already runs SeaweedFS' => [['services' => ['seaweedfs' => ['enabled' => true]]], [], false],
    'Commons already runs MinIO' => [['services' => ['minio' => ['enabled' => true]]], [], false],
    '--no-plex keeps files on a volume' => [null, ['no-plex' => true], false],
]);
