<?php

use App\Enums\ClusterTool;
use App\Support\Labels;
use App\Support\ToolRevision;
use Illuminate\Support\Facades\Process;

/** @param  list<array<string, mixed>>  $workloads */
function toolOutdatedFakes(array $registry, array $workloads = []): array
{
    return [
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode($registry))),
        '*get deployment,statefulset,daemonset -A*' => Process::result(output: json_encode(['items' => $workloads])),
        '*' => Process::result(output: ''),
    ];
}

/** @param  array<string, string>  $annotations */
function toolOutdatedWorkload(string $tool, string $instance, array $annotations = [], string $image = 'example/image:1'): array
{
    return [
        'metadata' => [
            'labels' => [Labels::MANAGED_BY => 'larakube', Labels::TOOL => $tool, Labels::INSTANCE => $instance],
            'annotations' => $annotations,
        ],
        'spec' => ['template' => ['spec' => ['containers' => [['image' => $image]]]]],
    ];
}

test('tool:outdated tells current, outdated and never-stamped instances apart', function (): void {
    Process::fake(toolOutdatedFakes(
        [
            ['tool' => 'n8n', 'instance' => 'flow-example-com', 'host' => 'flow.example.com'],
            ['tool' => 'headlamp', 'instance' => 'dash-example-com', 'host' => 'dash.example.com'],
            ['tool' => 'outline', 'instance' => 'notes-example-com', 'host' => 'notes.example.com'],
        ],
        [
            toolOutdatedWorkload('n8n', 'flow-example-com', [Labels::REVISION => ToolRevision::for(ClusterTool::N8N)], 'n8nio/n8n:2.47.7'),
            toolOutdatedWorkload('headlamp', 'dash-example-com', [Labels::REVISION => 'aaaaaaaaaaaa', Labels::INSTALL_OPTIONS => '{"vpn-only":true}']),
            // Outline still carries no stamp: installed before revisions existed.
            toolOutdatedWorkload('outline', 'notes-example-com'),
        ],
    ));

    expect(Artisan::call('tool:outdated --context=test --json'))->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    $rows = collect($decoded)->keyBy('tool');

    expect($rows['n8n']['status'])->toBe('current')
        ->and($rows['n8n']['images'])->toBe(['n8nio/n8n:2.47.7'])
        ->and($rows['headlamp']['status'])->toBe('outdated')
        ->and($rows['headlamp']['installedRevision'])->toBe('aaaaaaaaaaaa')
        ->and($rows['headlamp']['installOptions'])->toBe(['vpn-only' => true])
        ->and($rows['outline']['status'])->toBe('unstamped')
        // Worst first, so the table leads with what needs attention.
        ->and(array_column($decoded, 'tool'))->toBe(['outline', 'headlamp', 'n8n']);
});

test('tool:outdated points at tool:update for the same cluster when anything is behind', function (): void {
    Process::fake(toolOutdatedFakes([['tool' => 'outline', 'instance' => 'notes-example-com', 'host' => 'notes.example.com']]));

    $this->artisan('tool:outdated --context=test')
        ->assertExitCode(0)
        ->expectsOutputToContain('tool:update --context=test --all');
});

test('a workload missing its stamp makes the whole instance count as unstamped', function (): void {
    // What a half-finished update looks like: one component re-applied, one not.
    Process::fake(toolOutdatedFakes(
        [['tool' => 'matrix', 'instance' => 'chat-example-com', 'host' => 'chat.example.com']],
        [
            toolOutdatedWorkload('matrix', 'chat-example-com', [Labels::REVISION => ToolRevision::for(ClusterTool::MATRIX)]),
            toolOutdatedWorkload('matrix', 'chat-example-com'),
        ],
    ));

    expect(Artisan::call('tool:outdated --context=test --json'))->toBe(0)
        ->and(json_decode(Artisan::output(), true)[0]['status'])->toBe('unstamped');
});

test('tool:outdated lists every container with its running image, main one first, and this build\'s pin where known', function (): void {
    $workload = fn (string $name, ?string $component, array $containers): array => [
        'metadata' => [
            'name' => $name,
            'labels' => array_filter([Labels::MANAGED_BY => 'larakube', Labels::TOOL => 'n8n', Labels::INSTANCE => 'flow-example-com', Labels::COMPONENT => $component]),
            'annotations' => [],
        ],
        'spec' => ['template' => ['spec' => ['containers' => $containers]]],
    ];

    Process::fake(toolOutdatedFakes(
        [['tool' => 'n8n', 'instance' => 'flow-example-com', 'host' => 'flow.example.com']],
        [
            $workload('zz-sidecar-flow-example-com', 'sidecar', [['name' => 'helper', 'image' => 'busybox:1.36']]),
            $workload(App\Data\ToolInstance::forInstance(ClusterTool::N8N, 'flow-example-com')->deployment(), 'n8n', [['name' => 'n8n', 'image' => 'docker.n8n.io/n8nio/n8n:2.39.8']]),
        ],
    ));

    expect(Artisan::call('tool:outdated --context=test --json'))->toBe(0);

    $containers = json_decode(Artisan::output(), true)[0]['containers'];

    expect($containers[0])->toBe([
        'component' => 'n8n',
        'container' => 'n8n',
        'running' => 'docker.n8n.io/n8nio/n8n:2.39.8',
        'available' => ClusterTool::N8N->vendor()->image('n8n'),
        'primary' => true,
        'digests' => [],
    ])
        ->and($containers[1]['running'])->toBe('busybox:1.36')
        ->and($containers[1]['primary'])->toBeFalse()
        // Nothing in n8n's pins mentions busybox: unknown, not "unchanged".
        ->and($containers[1]['available'])->toBeNull();
});

test('each container carries the digest its pods actually pulled, matched through the workload\'s selector', function (): void {
    $deployment = App\Data\ToolInstance::forInstance(ClusterTool::N8N, 'flow-example-com')->deployment();
    $digest = fn (string $c): string => 'sha256:'.str_repeat($c, 64);
    $pod = fn (string $app, string $imageId): array => [
        'metadata' => ['namespace' => 'larakube-shared', 'labels' => ['app' => $app, 'pod-template-hash' => 'x']],
        'status' => ['containerStatuses' => [['name' => 'n8n', 'imageID' => "docker.n8n.io/n8nio/n8n@{$imageId}"]]],
    ];

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([['tool' => 'n8n', 'instance' => 'flow-example-com', 'host' => 'flow.example.com']]))),
        '*get deployment,statefulset,daemonset -A*' => Process::result(output: json_encode(['items' => [[
            'metadata' => [
                'name' => $deployment,
                'namespace' => 'larakube-shared',
                'labels' => [Labels::MANAGED_BY => 'larakube', Labels::TOOL => 'n8n', Labels::INSTANCE => 'flow-example-com', Labels::COMPONENT => 'n8n'],
                'annotations' => [],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['app' => $deployment]],
                'template' => ['spec' => ['containers' => [['name' => 'n8n', 'image' => 'n8nio/n8n:2.47.7']]]],
            ],
        ]]])),
        '*get pods -A*' => Process::result(output: json_encode(['items' => [
            // Mid-rollout: two pods of this Deployment on different digests.
            $pod($deployment, $digest('a')),
            $pod($deployment, $digest('b')),
            // Another workload's pod in the same namespace is never counted.
            $pod('something-else', $digest('c')),
        ]])),
        '*' => Process::result(output: ''),
    ]);

    expect(Artisan::call('tool:outdated --context=test --json'))->toBe(0)
        ->and(json_decode(Artisan::output(), true)[0]['containers'][0]['digests'])->toBe([$digest('a'), $digest('b')]);
});
