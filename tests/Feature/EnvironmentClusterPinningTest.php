<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Commands that take an environment act on THAT environment's saved cluster,
 * never on whatever cluster the shell points at. They used to run bare
 * `kubectl`, so `stop production` scaled down the current context.
 */
function inPinningProject(callable $run, bool $withCluster = true): void
{
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $config = new ConfigData(name: 'shop');
    $config->setEnvironments(['local', 'production']);
    if ($withCluster) {
        $config->setCloud('production', new CloudData(ip: '203.0.113.10', user: 'deploy'));
    }
    $config->saveToFile($directory->path());

    $original = getcwd();
    chdir($directory->path());

    try {
        $run();
    } finally {
        chdir($original);
        $directory->delete();
    }
}

function pinnedToProduction(PendingProcess $process): bool
{
    return str_contains($process->command, "--context 'larakube-203.0.113.10'");
}

test('stop and start act on the environment\'s saved cluster', function (string $command): void {
    Process::fake(function (PendingProcess $process) use ($command) {
        $cmd = $process->command;

        if (str_contains($cmd, 'get deployments') && str_contains($cmd, '-o json')) {
            // stop needs a live (>=1) replica to act on; start needs it at 0.
            $liveReplicas = $command === 'stop' ? 2 : 0;

            return Process::result(output: json_encode(['items' => [
                ['metadata' => ['name' => 'web'], 'spec' => ['replicas' => $liveReplicas]],
            ]]));
        }

        if (str_contains($cmd, 'annotations.larakube\.app/paused-replicas')) {
            return Process::result(output: ' 2 ');
        }

        return Process::result(output: '');
    });

    inPinningProject(fn () => $this->artisan("{$command} production")->assertExitCode(0));

    Process::assertRan(fn (PendingProcess $p) => str_contains($p->command, 'scale deployment/web -n')
        && pinnedToProduction($p));
    Process::assertNotRan(fn (PendingProcess $p) => str_starts_with($p->command, 'kubectl scale'));
})->with(['stop', 'start']);

test('a cloud environment with no saved cluster refuses instead of using the current context', function (string $command): void {
    Process::fake(['*' => Process::result(output: '')]);

    inPinningProject(fn () => $this->artisan("{$command} production")
        ->expectsOutputToContain("'production' has no saved cluster")
        ->assertExitCode(1), withCluster: false);

    Process::assertNotRan(fn (PendingProcess $p) => str_contains($p->command, 'scale deployment')
        || str_contains($p->command, 'get volumesnapshot'));
})->with(['stop', 'start', 'snapshot:list']);

test('snapshot:list reads the environment\'s saved cluster', function (): void {
    Process::fake(['*' => Process::result(output: '{"items":[]}')]);

    inPinningProject(fn () => $this->artisan('snapshot:list production'));

    Process::assertRan(fn (PendingProcess $p) => str_contains($p->command, 'get volumesnapshot') && pinnedToProduction($p));
});

test('about reads pods from the environment\'s saved cluster', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    inPinningProject(fn () => $this->artisan('about production'));

    Process::assertRan(fn (PendingProcess $p) => str_contains($p->command, 'get pods -n shop-production') && pinnedToProduction($p));
});
