<?php

/**
 * The graceful reload shared by `reload` and `watch`: which processes a
 * project has to reload, and a per-target result Desktop can show.
 */

use App\Data\ConfigData;
use App\Enums\LaravelFeature;
use App\Enums\ServerVariation;
use App\Traits\LaraKubeOutput;
use App\Traits\ReloadsLongRunningPods;
use Illuminate\Support\Facades\Process;

function reloadsLongRunningPodsHarness(): object
{
    return new class
    {
        use LaraKubeOutput, ReloadsLongRunningPods;

        public function reloadAll(array $targets): array
        {
            return $this->reloadTargetsIn($targets, 'shop-local', 'kubectl');
        }
    };
}

test('reload targets cover Octane, Horizon, queues and Reverb, and nothing else', function (): void {
    $config = new ConfigData(
        name: 'shop',
        path: getcwd(),
        features: [LaravelFeature::HORIZON, LaravelFeature::QUEUES, LaravelFeature::REVERB, LaravelFeature::TASK_SCHEDULING],
    );
    $config->setServerVariation(ServerVariation::FRANKENPHP);

    expect(reloadsLongRunningPodsHarness()::reloadTargets($config))->toBe([
        ServerVariation::FRANKENPHP->getPodName($config) => 'php artisan octane:reload',
        'horizon' => 'php artisan horizon:terminate',
        'queues' => 'php artisan queue:restart',
        'reverb' => 'php artisan reverb:restart',
    ]);
});

function reloadPodsJson(array $pods): string
{
    return (string) json_encode(['items' => array_map(fn (array $pod): array => [
        'metadata' => ['name' => $pod[0], 'labels' => ['app' => $pod[1]]],
        'status' => ['phase' => $pod[2] ?? 'Running'],
    ], $pods)]);
}

test('every target reloads in parallel after a single pod lookup', function (): void {
    Process::fake([
        '*get pods*' => Process::result(reloadPodsJson([['horizon-1', 'horizon'], ['horizon-2', 'horizon'], ['reverb-1', 'reverb']])),
        '*exec*' => Process::result("done\n"),
    ]);

    $results = reloadsLongRunningPodsHarness()->reloadAll([
        'horizon' => 'php artisan horizon:terminate',
        'reverb' => 'php artisan reverb:restart',
    ]);

    expect($results)->toBe([
        ['target' => 'horizon', 'pods' => ['horizon-1', 'horizon-2'], 'ok' => true, 'error' => null],
        ['target' => 'reverb', 'pods' => ['reverb-1'], 'ok' => true, 'error' => null],
    ]);

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'get pods'), 1);
    Process::assertRan(fn ($process) => str_contains($process->command, 'exec -n shop-local -c php horizon-2 -- php artisan horizon:terminate'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'exec -n shop-local -c php reverb-1 -- php artisan reverb:restart'));
});

test('a failed reload carries the last line of its error and leaves the others alone', function (): void {
    Process::fake([
        '*get pods*' => Process::result(reloadPodsJson([['horizon-1', 'horizon'], ['reverb-1', 'reverb']])),
        '*exec*reverb-1*' => Process::result(errorOutput: "error: unable to upgrade connection\ncontainer not found (\"php\")\n", exitCode: 1),
        '*exec*' => Process::result("done\n"),
    ]);

    [$horizon, $reverb] = reloadsLongRunningPodsHarness()->reloadAll([
        'horizon' => 'php artisan horizon:terminate',
        'reverb' => 'php artisan reverb:restart',
    ]);

    expect($horizon['ok'])->toBeTrue()
        ->and($reverb['ok'])->toBeFalse()
        ->and($reverb['error'])->toBe('container not found ("php")');
});

test('a missing pod is a failed result, not a crash', function (): void {
    Process::fake(['*get pods*' => Process::result(reloadPodsJson([]))]);

    expect(reloadsLongRunningPodsHarness()->reloadAll(['horizon' => 'php artisan horizon:terminate'])[0])
        ->toMatchArray(['ok' => false, 'pods' => [], 'error' => 'No horizon pod found in shop-local.']);
});

test('running pods are preferred, and queue workers are found under their older labels', function (): void {
    $pods = [
        ['name' => 'horizon-old', 'app' => 'horizon', 'phase' => 'Pending'],
        ['name' => 'horizon-new', 'app' => 'horizon', 'phase' => 'Running'],
        ['name' => 'worker-1', 'app' => 'queue', 'phase' => 'Running'],
        ['name' => 'reverb-1', 'app' => 'reverb', 'phase' => 'Pending'],
    ];

    expect(reloadsLongRunningPodsHarness()::podsFor('horizon', $pods))->toBe(['horizon-new'])
        ->and(reloadsLongRunningPodsHarness()::podsFor('queues', $pods))->toBe(['worker-1'])
        ->and(reloadsLongRunningPodsHarness()::podsFor('reverb', $pods))->toBe(['reverb-1']);
});
