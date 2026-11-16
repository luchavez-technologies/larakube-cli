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
            toolOutdatedWorkload('n8n', 'flow-example-com', [Labels::REVISION => ToolRevision::for(ClusterTool::N8N)], 'docker.n8n.io/n8nio/n8n:2.47.7'),
            toolOutdatedWorkload('headlamp', 'dash-example-com', [Labels::REVISION => 'aaaaaaaaaaaa', Labels::INSTALL_OPTIONS => '{"vpn-only":true}']),
            // Outline still carries no stamp: installed before revisions existed.
            toolOutdatedWorkload('outline', 'notes-example-com'),
        ],
    ));

    expect(Artisan::call('tool:outdated --context=test --json'))->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    $rows = collect($decoded)->keyBy('tool');

    expect($rows['n8n']['status'])->toBe('current')
        ->and($rows['n8n']['images'])->toBe(['docker.n8n.io/n8nio/n8n:2.47.7'])
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
