<?php

use App\Data\ConfigData;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * `stop` scales every Deployment in the environment's namespace to zero,
 * remembering each one's live replica count as a `larakube.app/paused-replicas`
 * annotation first (mirrors Cluster Tools' tool:pause). $calls collects the
 * annotate/scale commands the fake cluster receives, in order.
 */
function stopFakeCluster(?array &$calls, array $deployments): void
{
    $calls = [];
    $items = [];
    foreach ($deployments as $name => $replicas) {
        $items[] = ['metadata' => ['name' => $name], 'spec' => ['replicas' => $replicas]];
    }
    $listJson = json_encode(['items' => $items]);

    Process::fake(function ($process) use (&$calls, $listJson) {
        $cmd = (string) $process->command;

        return match (true) {
            str_contains($cmd, 'get deployments') && str_contains($cmd, '-o json') => Process::result(output: $listJson),
            str_contains($cmd, ' annotate deployment ') || str_contains($cmd, ' scale deployment/') => (function () use (&$calls, $cmd) {
                $calls[] = $cmd;

                return Process::result(output: 'ok');
            })(),
            default => Process::result(output: ''),
        };
    });
}

function stopTestProject(string $tempDir): void
{
    (new ConfigData(name: 'test-app'))->saveToFile($tempDir);
}

test('stop annotates each live deployment with its replica count then scales it to zero', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    stopTestProject($tempDir);
    stopFakeCluster($calls, ['web' => 3, 'worker' => 2]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('stop local')->assertExitCode(0);

        expect($calls)->toHaveCount(4)
            ->and($calls[0])->toContain('annotate deployment web')
            ->and($calls[0])->toContain('larakube.app/paused-replicas=3')
            ->and($calls[0])->toContain('--overwrite')
            ->and($calls[1])->toContain('scale deployment/web')
            ->and($calls[1])->toContain('--replicas=0')
            ->and($calls[2])->toContain('annotate deployment worker')
            ->and($calls[2])->toContain('larakube.app/paused-replicas=2')
            ->and($calls[3])->toContain('scale deployment/worker')
            ->and($calls[3])->toContain('--replicas=0');
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('stop is a no-op for a deployment already at zero replicas', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    stopTestProject($tempDir);
    stopFakeCluster($calls, ['web' => 3, 'already-stopped' => 0]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('stop local')->assertExitCode(0);

        expect($calls)->toHaveCount(2)
            ->and(collect($calls)->filter(fn (string $c) => str_contains($c, 'already-stopped')))->toBeEmpty();
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('stop against a namespace with no deployments makes no annotate/scale calls', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    stopTestProject($tempDir);
    stopFakeCluster($calls, []);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('stop local')->assertExitCode(0);

        expect($calls)->toBe([]);
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});
