<?php

use App\Support\Labels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

function toolLogsJsonFakes(): array
{
    FakeToolRegistry::install([['tool' => 'matrix', 'instance' => 'chat-example-com', 'host' => 'chat.example.com']]);

    $pods = ['items' => [
        ['metadata' => ['name' => 'synapse-chat-example-com-abc', 'labels' => [Labels::COMPONENT => 'synapse']]],
        ['metadata' => ['name' => 'mas-chat-example-com-def', 'labels' => [Labels::COMPONENT => 'mas']]],
    ]];

    return [
        '*get pods*' => Process::result(output: json_encode($pods)),
        '* logs *' => Process::result(output: implode("\n", [
            '[pod/synapse-chat-example-com-abc/synapse] 2026-11-18T01:00:02.000000000Z started',
            '[pod/mas-chat-example-com-def/mas] 2026-11-18T01:00:01.000000000Z listening',
            'not a log line',
        ])),
        '*' => Process::result(output: ''),
    ];
}

test('--json merges every component\'s lines in time order, each tagged with its component', function (): void {
    Process::fake(toolLogsJsonFakes());

    Artisan::call('matrix:logs', ['--json' => true, '--domain' => 'chat.example.com']);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['components'])->toEqualCanonicalizing(['synapse', 'mas'])
        ->and(array_column($payload['lines'], 'component'))->toBe(['mas', 'synapse'])
        ->and($payload['lines'][0])->toMatchArray(['pod' => 'mas-chat-example-com-def', 'container' => 'mas', 'line' => 'listening']);
});

test('--json passes --since-time through, so a caller can ask only for new lines', function (): void {
    Process::fake(toolLogsJsonFakes());

    Artisan::call('matrix:logs', ['--json' => true, '--domain' => 'chat.example.com', '--since-time' => '2026-11-18T01:00:00Z']);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, ' logs ')
        && str_contains((string) $process->command, '--since-time=2026-11-18T01:00:00Z')
        && str_contains((string) $process->command, '--prefix'));
});

test('--json reads a tool registered without an instance through its own Deployments\' pod selectors', function (): void {
    // ExternalDNS: registered with an empty instance, its pods labelled only `app=` and `dns-zone=`.
    FakeToolRegistry::install([['tool' => 'external-dns', 'instance' => '']]);

    Process::fake([
        '*get deployments*' => Process::result(output: json_encode(['items' => [
            ['metadata' => ['name' => 'external-dns-larakube-app'], 'spec' => ['selector' => ['matchLabels' => ['app' => 'external-dns-larakube-app']]]],
            ['metadata' => ['name' => 'traefik'], 'spec' => ['selector' => ['matchLabels' => ['app' => 'traefik']]]],
        ]])),
        '*get pods*app=external-dns-larakube-app*' => Process::result(output: json_encode(['items' => [
            ['metadata' => ['name' => 'external-dns-larakube-app-7d5b-qsbdh', 'labels' => ['app' => 'external-dns-larakube-app']]],
        ]])),
        '* logs *app=external-dns-larakube-app*' => Process::result(output: '[pod/external-dns-larakube-app-7d5b-qsbdh/external-dns] 2026-11-19T12:31:42.000000000Z All records are already up to date'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('external-dns:logs', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload['instance'])->toBeNull()
        ->and($payload['lines'])->toHaveCount(1)
        ->and($payload['lines'][0]['line'])->toBe('All records are already up to date');
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'app=traefik'));
});
