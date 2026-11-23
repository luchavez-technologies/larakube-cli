<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * plex:outdated and plex:update: the running Commons compared with what this
 * CLI build would apply, and re-applied keeping its services and settings.
 */
function plexUpdateTemplate(string $image, array $extra = []): array
{
    return ['metadata' => ['labels' => ['app' => 'x']], 'spec' => ['containers' => [['name' => 'main', 'image' => $image, ...$extra]]]];
}

/**
 * @param  array<string, array>  $rendered  service => template the dry run returns
 * @param  array<string, array>  $live  service => template running now
 */
function plexUpdateFakes(array $rendered, array $live, ?array &$applied = null): array
{
    $applied = [];

    return [
        '*get configmap plex-commons*' => Process::result(output: (string) json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true, 'image' => 'postgres:17.9'], 'redis' => ['enabled' => true]],
        ])),
        '*apply --dry-run=server -o json*' => Process::result(output: (string) json_encode(['kind' => 'List', 'items' => collect($rendered)->map(
            fn (array $template, string $name): array => ['kind' => 'Deployment', 'metadata' => ['name' => $name], 'spec' => ['template' => $template]],
        )->values()->all()])),
        ...collect($live)->mapWithKeys(fn (array $template, string $name): array => [
            "*get deployment {$name} -n larakube-plex -o json*" => Process::result(output: (string) json_encode(['spec' => ['template' => $template]])),
        ])->all(),
        '*apply -n larakube-plex -f*' => function (PendingProcess $process) use (&$applied) {
            $applied[] = $process->command;

            return Process::result(output: 'applied');
        },
        '*' => Process::result(output: ''),
    ];
}

/** The command's last stdout line: its JSON result. */
function plexUpdateJson(string $command, array $arguments): array
{
    Artisan::call($command, ['--context' => 'ctx', '--json' => true, ...$arguments]);
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

test('plex:outdated lists the services this build would change and applies nothing', function (): void {
    Process::fake(plexUpdateFakes(
        ['postgres' => plexUpdateTemplate('pgvector/pgvector:0.8.7-pg17-trixie'), 'redis' => plexUpdateTemplate('valkey/valkey:8.0-alpine', ['livenessProbe' => ['tcpSocket' => ['port' => 6379]]])],
        ['postgres' => plexUpdateTemplate('postgres:17.9'), 'redis' => plexUpdateTemplate('valkey/valkey:8.0-alpine')],
        $applied,
    ));

    $result = plexUpdateJson('plex:outdated', []);

    expect($result['success'])->toBeTrue()
        ->and($result['outdated'])->toBe([
            ['service' => 'postgres', 'stateful' => true, 'from' => 'postgres:17.9', 'to' => 'pgvector/pgvector:0.8.7-pg17-trixie', 'paused' => false],
            ['service' => 'redis', 'stateful' => true, 'from' => 'valkey/valkey:8.0-alpine', 'to' => 'valkey/valkey:8.0-alpine', 'paused' => false],
        ])
        ->and($applied)->toBe([]);
});

test('plex:update re-applies the Commons and waits for each changed service', function (): void {
    Process::fake(plexUpdateFakes(
        ['postgres' => plexUpdateTemplate('pgvector/pgvector:0.8.7-pg17-trixie'), 'redis' => plexUpdateTemplate('valkey/valkey:8.0-alpine')],
        ['postgres' => plexUpdateTemplate('postgres:17.9'), 'redis' => plexUpdateTemplate('valkey/valkey:8.0-alpine')],
        $applied,
    ));

    $result = plexUpdateJson('plex:update', ['--confirm-commons-restart' => true]);

    expect($result['success'])->toBeTrue()
        ->and($result['updated'])->toBe(['postgres'])
        ->and($applied)->toHaveCount(1);

    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout status deploy/postgres'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout status deploy/redis'));
});

test('plex:update keeps the saved services and moves plain Postgres 17 to the pgvector image', function (): void {
    $sent = null;
    $fakes = plexUpdateFakes([], []);
    $fakes['*apply --dry-run=server -o json*'] = function (PendingProcess $process) use (&$sent) {
        preg_match("/-f '([^']+)'/", $process->command, $matches);
        $sent = file_get_contents($matches[1]);

        return Process::result(output: (string) json_encode(['kind' => 'List', 'items' => []]));
    };
    Process::fake($fakes);

    plexUpdateJson('plex:update', ['--dry-run' => true]);

    expect($sent)->toContain('image: pgvector/pgvector:0.8.7-pg17-trixie')
        ->toContain('name: redis')
        ->not->toContain('name: meilisearch');
});

test('an up-to-date Commons is left alone', function (): void {
    $same = plexUpdateTemplate('valkey/valkey:8.0-alpine');
    Process::fake(plexUpdateFakes(['redis' => $same], ['redis' => $same], $applied));

    expect(plexUpdateJson('plex:update', ['--confirm-commons-restart' => true]))->toBe(['success' => true, 'updated' => [], 'changes' => []])
        ->and($applied)->toBe([]);
});

test('a dry run lists the changes and applies nothing', function (): void {
    Process::fake(plexUpdateFakes(
        ['postgres' => plexUpdateTemplate('pgvector/pgvector:0.8.7-pg17-trixie')],
        ['postgres' => plexUpdateTemplate('postgres:17.9')],
        $applied,
    ));

    $result = plexUpdateJson('plex:update', ['--dry-run' => true]);

    expect($result)->toMatchArray(['success' => true, 'updated' => [], 'dryRun' => true])
        ->and($result['changes'][0]['service'])->toBe('postgres')
        ->and($applied)->toBe([]);
});

test('without confirmation a restarting update is refused and nothing is applied', function (): void {
    Process::fake(plexUpdateFakes(
        ['postgres' => plexUpdateTemplate('pgvector/pgvector:0.8.7-pg17-trixie')],
        ['postgres' => plexUpdateTemplate('postgres:17.9')],
        $applied,
    ));

    $result = plexUpdateJson('plex:update', []);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('The update was not applied.')
        ->and($applied)->toBe([]);
});

test('with no Commons on the cluster both commands say to run plex:init', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    expect(plexUpdateJson('plex:update', [])['error'])->toContain('Run `larakube plex:init` first')
        ->and(plexUpdateJson('plex:outdated', [])['error'])->toContain('Run `larakube plex:init` first');
});

test('plex:update --service applies only that service\'s Deployment', function (): void {
    $sent = null;
    $fakes = plexUpdateFakes(
        ['postgres' => plexUpdateTemplate('pgvector/pgvector:0.8.7-pg17-trixie'), 'redis' => plexUpdateTemplate('valkey/valkey:8.0-alpine', ['livenessProbe' => ['tcpSocket' => ['port' => 6379]]])],
        ['postgres' => plexUpdateTemplate('postgres:17.9'), 'redis' => plexUpdateTemplate('valkey/valkey:8.0-alpine')],
        $applied,
    );
    $fakes['*apply -n larakube-plex -f*'] = function (PendingProcess $process) use (&$sent) {
        $sent = $process->input; // kubectl apply -f - reads the manifest from stdin

        return Process::result(output: 'applied');
    };
    Process::fake($fakes);

    $result = plexUpdateJson('plex:update', ['--service' => 'redis', '--confirm-commons-restart' => true]);

    expect($result['success'])->toBeTrue()
        ->and($result['updated'])->toBe(['redis'])
        ->and((string) $sent)->toContain("kind: Deployment\nmetadata:\n  name: redis")
        ->not->toContain('name: postgres')
        ->not->toContain('kind: ConfigMap');
});

test('plex:update --service refuses a service the Commons does not run', function (): void {
    Process::fake(plexUpdateFakes([], [], $applied));

    expect(plexUpdateJson('plex:update', ['--service' => 'mysql'])['error'])->toBe("'mysql' is not a service on this Commons.")
        ->and($applied)->toBe([]);
});

test('a paused service is updated without being started, and is not counted as a restart', function (): void {
    $fakes = plexUpdateFakes(
        ['postgres' => plexUpdateTemplate('pgvector/pgvector:0.8.7-pg17-trixie')],
        [],
        $applied,
    );
    Process::fake([
        '*get deployment postgres -n larakube-plex -o json*' => Process::result(output: (string) json_encode([
            'spec' => ['replicas' => 0, 'template' => plexUpdateTemplate('postgres:17.9')],
        ])),
        '*get deployments -n larakube-plex -o json*' => Process::result(output: (string) json_encode([
            'items' => [['metadata' => ['name' => 'postgres'], 'spec' => ['replicas' => 0]]],
        ])),
        ...$fakes,
    ]);

    // No --confirm-commons-restart: nothing running restarts, so nothing to confirm.
    $result = plexUpdateJson('plex:update', []);

    expect($result['success'])->toBeTrue()
        ->and($result['changes'][0])->toMatchArray(['service' => 'postgres', 'paused' => true])
        ->and($applied)->toHaveCount(1);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout status deploy/postgres'));
});
