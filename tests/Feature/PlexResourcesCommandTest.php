<?php

use Illuminate\Support\Facades\Process;

function plexResourcesFakes(array $overrides = []): array
{
    return array_merge([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode([
                'version' => 1,
                'services' => [
                    'postgres' => [
                        'enabled' => true,
                        'image' => 'postgres:17.9',
                        'port' => 5432,
                        'storage' => '10Gi',
                        'memory' => '1Gi',
                        'cpu' => '500m',
                        'max_connections' => 200,
                    ],
                    'redis' => [
                        'enabled' => true,
                        'image' => 'valkey/valkey:8.0-alpine',
                        'port' => 6379,
                        'memory' => '128Mi',
                        'cpu' => '250m',
                        'maxclients' => 10000,
                    ],
                    'seaweedfs' => [
                        'enabled' => true,
                        'image' => 'chrislusf/seaweedfs:latest',
                        'port' => 8333,
                        'storage' => '10Gi',
                        'memory' => '512Mi',
                        'cpu' => '500m',
                    ],
                ],
            ]),
        ),
        '*cluster-info*' => Process::result(output: 'reachable'),
        '*apply --dry-run=server -o json*' => Process::result(output: (string) json_encode(['kind' => 'List', 'items' => []])),
        '*apply*' => Process::result(output: 'applied'),
        '*rollout status*' => Process::result(output: 'rolled out'),
    ], $overrides, [
        '*' => Process::result(output: ''),
    ]);
}

test('plex:resources configures postgres CPU, memory, and max_connections non-interactively with --json', function (): void {
    Process::fake(plexResourcesFakes());

    $exitCode = Artisan::call('plex:resources', [
        '--context' => 'test-cluster',
        '--service' => 'postgres',
        '--cpu' => '1500m',
        '--memory' => '2Gi',
        '--max-connections' => '350',
        '--json' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('"success": true')
        ->and($output)->toContain('"cpu": "1500m"')
        ->and($output)->toContain('"memory": "2Gi"')
        ->and($output)->toContain('"max_connections": 350');
});

test('plex:resources configures redis CPU, memory, and maxclients non-interactively with --json', function (): void {
    Process::fake(plexResourcesFakes());

    $exitCode = Artisan::call('plex:resources', [
        '--context' => 'test-cluster',
        '--service' => 'redis',
        '--cpu' => '750m',
        '--memory' => '512Mi',
        '--maxclients' => '25000',
        '--json' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('"success": true')
        ->and($output)->toContain('"cpu": "750m"')
        ->and($output)->toContain('"maxclients": 25000');
});

test('plex:resources configures seaweedfs CPU, memory, and storage non-interactively with --json', function (): void {
    Process::fake(plexResourcesFakes());

    $exitCode = Artisan::call('plex:resources', [
        '--context' => 'test-cluster',
        '--service' => 'seaweedfs',
        '--cpu' => '2000m',
        '--memory' => '4Gi',
        '--storage' => '50Gi',
        '--json' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('"success": true')
        ->and($output)->toContain('"cpu": "2000m"')
        ->and($output)->toContain('"memory": "4Gi"')
        ->and($output)->toContain('"storage": "50Gi');
});

test('plex:resources enables PgBouncer pooler non-interactively with --pooler flag', function (): void {
    Process::fake(plexResourcesFakes());

    $exitCode = Artisan::call('plex:resources', [
        '--context' => 'test-cluster',
        '--service' => 'postgres',
        '--pooler' => 'on',
        '--pool-mode' => 'session',
        '--pool-size' => '30',
        '--max-clients' => '500',
        '--json' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('"success": true')
        ->and($output)->toContain('"enabled": true')
        ->and($output)->toContain('"mode": "session"')
        ->and($output)->toContain('"poolSize": 30')
        ->and($output)->toContain('"maxClients": 500');
});

test('plex:resources resets a service to defaults with --reset flag', function (): void {
    Process::fake(plexResourcesFakes());

    $exitCode = Artisan::call('plex:resources', [
        '--context' => 'test-cluster',
        '--service' => 'postgres',
        '--reset' => true,
        '--json' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('"success": true')
        ->and($output)->toContain('"cpu": "500m"')
        ->and($output)->toContain('"max_connections": 200');
});
