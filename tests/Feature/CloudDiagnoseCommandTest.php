<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * The --json result line, same convention as TlsShowCommandTest.
 *
 * @return array<string, mixed>
 */
function diagnoseJsonReport(): array
{
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

/**
 * @param  array<string, mixed>  $nodes
 * @param  array<string, mixed>  $pods
 * @param  array<string, mixed>  $events
 * @return array<string, mixed>
 */
function diagnoseFakes(array $nodes = [], array $pods = [], array $events = []): array
{
    return [
        '*get nodes -o json*' => Process::result(output: (string) json_encode(['items' => $nodes])),
        '*get pods -A -o json*' => Process::result(output: (string) json_encode(['items' => $pods])),
        '*get events -A --field-selector=type=Warning -o json*' => Process::result(output: (string) json_encode(['items' => $events])),
        '*' => Process::result(output: ''),
    ];
}

function diagnoseHealthyNode(): array
{
    return ['metadata' => ['name' => 'node-1'], 'status' => ['conditions' => [
        ['type' => 'Ready', 'status' => 'True'],
        ['type' => 'MemoryPressure', 'status' => 'False'],
        ['type' => 'DiskPressure', 'status' => 'False'],
        ['type' => 'PIDPressure', 'status' => 'False'],
    ]]];
}

test('a healthy cluster reports no issues', function (): void {
    Process::fake(diagnoseFakes(nodes: [diagnoseHealthyNode()]));

    $this->artisan('cloud:diagnose production --context=ctx')
        ->expectsOutputToContain('No node pressure')
        ->assertExitCode(0);
});

test('node MemoryPressure is explained in plain language, not just the condition name', function (): void {
    Process::fake(diagnoseFakes(nodes: [
        ['metadata' => ['name' => 'node-1'], 'status' => ['conditions' => [
            ['type' => 'Ready', 'status' => 'True'],
            ['type' => 'MemoryPressure', 'status' => 'True', 'message' => 'node has insufficient memory'],
        ]]],
    ]));

    $this->artisan('cloud:diagnose production --context=ctx')
        ->expectsOutputToContain('node-1 is low on memory')
        ->assertExitCode(0);
});

test('an OOMKilled container is reported with its restart count, not left silent', function (): void {
    Process::fake(diagnoseFakes(
        nodes: [diagnoseHealthyNode()],
        pods: [[
            'metadata' => ['name' => 'wordpress-abc', 'namespace' => 'apps'],
            'status' => [
                'phase' => 'Running',
                'restartCount' => 3,
                'containerStatuses' => [[
                    'restartCount' => 3,
                    'lastState' => ['terminated' => ['reason' => 'OOMKilled']],
                ]],
            ],
        ]],
    ));

    $this->artisan('cloud:diagnose production --context=ctx')
        ->expectsOutputToContain('wordpress-abc ran out of memory')
        ->assertExitCode(0);

    Artisan::call('cloud:diagnose', ['environment' => 'production', '--context' => 'ctx', '--json' => true]);
    expect(diagnoseJsonReport()['issues'][0]['description'])->toContain('restarted 3 time(s)');
});

test('a pod stuck Pending for insufficient resources is explained, not just "Couldn\'t check"', function (): void {
    Process::fake(diagnoseFakes(
        nodes: [diagnoseHealthyNode()],
        pods: [[
            'metadata' => ['name' => 'n8n-main', 'namespace' => 'apps'],
            'status' => [
                'phase' => 'Pending',
                'conditions' => [[
                    'type' => 'PodScheduled',
                    'status' => 'False',
                    'message' => '0/1 nodes are available: 1 Insufficient memory.',
                ]],
            ],
        ]],
    ));

    $this->artisan('cloud:diagnose production --context=ctx')
        ->expectsOutputToContain("n8n-main can't start")
        ->assertExitCode(0);

    Artisan::call('cloud:diagnose', ['environment' => 'production', '--context' => 'ctx', '--json' => true]);
    expect(diagnoseJsonReport()['issues'][0]['description'])->toContain('Insufficient memory');
});

test('a recent eviction is surfaced from cluster events', function (): void {
    Process::fake(diagnoseFakes(
        nodes: [diagnoseHealthyNode()],
        events: [[
            'reason' => 'Evicted',
            'message' => 'The node was low on resource: memory.',
            'involvedObject' => ['name' => 'grafana-xyz'],
        ]],
    ));

    $this->artisan('cloud:diagnose production --context=ctx')
        ->expectsOutputToContain('grafana-xyz was evicted')
        ->assertExitCode(0);
});

test('an unreachable cluster fails clearly instead of reporting a false-healthy result', function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('cloud:diagnose production --context=ctx')
        ->expectsOutputToContain('Could not reach')
        ->assertExitCode(1);
});

test('cloud:diagnose --json reports structured issues on one stdout line', function (): void {
    Process::fake(diagnoseFakes(nodes: [
        ['metadata' => ['name' => 'node-1'], 'status' => ['conditions' => [
            ['type' => 'MemoryPressure', 'status' => 'True', 'message' => 'node has insufficient memory'],
        ]]],
    ]));

    Artisan::call('cloud:diagnose', ['environment' => 'production', '--context' => 'ctx', '--json' => true]);
    $report = diagnoseJsonReport();

    expect($report['success'])->toBeTrue()
        ->and($report['issues'])->toHaveCount(1)
        ->and($report['issues'][0]['title'])->toBe('node-1 is low on memory');
});
