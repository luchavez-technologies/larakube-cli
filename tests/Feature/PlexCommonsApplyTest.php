<?php

use App\Services\PlexService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Every Commons apply renders through one place and refuses to silently
 * restart a stateful service. A Commons re-apply once stripped the
 * Postgres/Redis exporters (a stale "is monitoring installed" check), which
 * restarted both and wiped Redis.
 */
function plexApplyTemplate(array $containers): array
{
    return ['metadata' => ['labels' => ['app' => 'x']], 'spec' => ['containers' => $containers]];
}

function plexApplyFakes(array $dryRunItems, array $live, ?array &$applied = null): array
{
    $applied = [];

    return [
        '*apply --dry-run=server -o json*' => Process::result(output: (string) json_encode(['kind' => 'List', 'items' => $dryRunItems])),
        ...collect($live)->mapWithKeys(fn ($template, $name) => [
            "*get deployment {$name} -n larakube-plex -o json*" => Process::result(output: (string) json_encode(['spec' => ['template' => $template]])),
        ])->all(),
        '*apply -n larakube-plex -f*' => function (PendingProcess $process) use (&$applied) {
            $applied[] = $process->command;

            return Process::result(output: 'applied');
        },
        '*' => Process::result(output: ''),
    ];
}

test('a changed pod template on a running stateful service is reported as a restart', function (): void {
    $withExporter = plexApplyTemplate([['name' => 'postgres'], ['name' => 'postgres-exporter']]);
    $without = plexApplyTemplate([['name' => 'postgres']]);

    Process::fake(plexApplyFakes([
        ['kind' => 'Deployment', 'metadata' => ['name' => 'postgres'], 'spec' => ['template' => $without]],
        ['kind' => 'Deployment', 'metadata' => ['name' => 'redis'], 'spec' => ['template' => plexApplyTemplate([['name' => 'redis']])]],
        // Stateless: never worth a warning.
        ['kind' => 'Deployment', 'metadata' => ['name' => 'headless-shell'], 'spec' => ['template' => plexApplyTemplate([['name' => 'new']])]],
        // Not deployed yet: creating it restarts nothing.
        ['kind' => 'Deployment', 'metadata' => ['name' => 'meilisearch'], 'spec' => ['template' => plexApplyTemplate([['name' => 'meili']])]],
    ], [
        'postgres' => $withExporter,
        'redis' => plexApplyTemplate([['name' => 'redis']]),
        'headless-shell' => plexApplyTemplate([['name' => 'old']]),
    ]));

    expect((new PlexService('ctx'))->restartsCausedBy('/tmp/commons.yaml'))->toBe(['postgres']);
});

test('without a terminal to confirm, a restarting Commons apply is refused and nothing is applied', function (): void {
    $applied = [];
    Process::fake(plexApplyFakes(
        [['kind' => 'Deployment', 'metadata' => ['name' => 'redis'], 'spec' => ['template' => plexApplyTemplate([['name' => 'redis']])]]],
        ['redis' => plexApplyTemplate([['name' => 'redis'], ['name' => 'redis-exporter']])],
        $applied,
    ));

    $this->artisan('plex:init local --services=postgres,redis --no-interaction')
        ->expectsOutputToContain('This change restarts running Commons service(s): redis.')
        ->expectsOutputToContain('sessions, caches and queued jobs are lost')
        ->assertExitCode(1);

    expect($applied)->toBe([]);
});

test('an apply that restarts nothing goes ahead', function (): void {
    $applied = [];
    $same = plexApplyTemplate([['name' => 'redis']]);
    Process::fake(plexApplyFakes(
        [['kind' => 'Deployment', 'metadata' => ['name' => 'redis'], 'spec' => ['template' => $same]]],
        ['redis' => $same],
        $applied,
    ));

    $this->artisan('plex:init local --services=postgres,redis --no-interaction');

    expect($applied)->toHaveCount(1);
});

test('with monitoring installed, the Commons keeps the Postgres and Redis exporters', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode([
            ['tool' => 'monitor', 'instance' => 'monitor-example-com', 'host' => 'monitor.example.com'],
        ]))),
        '*get deployment monitor-prometheus-monitor-example-com *' => Process::result(output: 'deployment.apps/monitor-prometheus-monitor-example-com'),
        '*' => Process::result(output: ''),
    ]);

    $plex = new PlexService('ctx');
    $manifest = $plex->renderCommonsManifest($plex->defaultCommonsSpec(), false);

    expect($manifest)->toContain('postgres-exporter')->toContain('redis-exporter');
});
