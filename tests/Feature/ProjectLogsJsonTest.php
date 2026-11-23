<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

function projectLogsJsonFakes(): array
{
    $pods = ['items' => [
        ['metadata' => ['name' => 'web-abc', 'labels' => ['app' => 'web']], 'status' => ['phase' => 'Running']],
        ['metadata' => ['name' => 'scheduler-29923396-xyz', 'labels' => ['job-name' => 'scheduler-29923396']], 'status' => ['phase' => 'Succeeded']],
        ['metadata' => ['name' => 'scheduler-29923397-new', 'labels' => ['job-name' => 'scheduler-29923397']], 'status' => ['phase' => 'Pending']],
    ]];

    return [
        '*get pods*' => Process::result(output: json_encode($pods)),
        "*logs 'web-abc'*" => Process::result(output: "[pod/web-abc/php] 2026-11-23T01:00:02.000000000Z GET /up\nnot a log line"),
        "*logs 'scheduler-29923396-xyz'*" => Process::result(output: '[pod/scheduler-29923396-xyz/php] 2026-11-23T01:00:01.000000000Z ran tasks'),
        '*' => Process::result(output: ''),
    ];
}

test('logs --json merges every pod in time order, naming a CronJob pod by its schedule', function (): void {
    Process::fake(projectLogsJsonFakes());

    Artisan::call('logs', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['success'])->toBeTrue()
        ->and($payload['components'])->toBe(['scheduler', 'web'])
        ->and(array_column($payload['lines'], 'component'))->toBe(['scheduler', 'web'])
        ->and($payload['lines'][1])->toMatchArray(['pod' => 'web-abc', 'container' => 'php', 'line' => 'GET /up']);
});

test('logs --json skips a Pending pod, which has no logs to read', function (): void {
    Process::fake(projectLogsJsonFakes());

    Artisan::call('logs', ['--json' => true]);

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, "logs 'scheduler-29923397-new'"));
});

test('logs --json --component reads only that service', function (): void {
    Process::fake(projectLogsJsonFakes());

    Artisan::call('logs', ['--json' => true, '--component' => 'web']);
    $payload = json_decode(Artisan::output(), true);

    expect(array_column($payload['lines'], 'component'))->toBe(['web']);
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, "logs 'scheduler-29923396-xyz'"));
});

test('logs --json passes --since-time through, so a caller can ask only for new lines', function (): void {
    Process::fake(projectLogsJsonFakes());

    Artisan::call('logs', ['--json' => true, '--since-time' => '2026-11-23T01:00:00Z']);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "logs 'web-abc'")
        && str_contains((string) $process->command, '--since-time=2026-11-23T01:00:00Z'));
});

test('logs --json reports a failure when the cluster cannot be reached', function (): void {
    Process::fake(['*get pods*' => Process::result(exitCode: 1), '*' => Process::result()]);

    $exit = Artisan::call('logs', ['--json' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true))->toMatchArray(['success' => false]);
});
