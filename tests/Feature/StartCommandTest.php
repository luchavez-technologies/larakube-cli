<?php

use App\Data\ConfigData;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * `start` scales every zero-replica Deployment in the environment's
 * namespace back up to its remembered `larakube.app/paused-replicas`
 * annotation (default 1 when absent), then clears the marker.
 *
 * Laravel's FakeProcessResult::normalizeOutput() runs the faked output
 * through empty($output), and PHP's empty('0') is true — so faking the
 * literal string "0" silently becomes "". Pad it; pausedReplicas() trims
 * the real output anyway. Mirrors the identical helper in
 * tests/Feature/ToolPauseCommandTest.php.
 */
function fakeStartReplicaOutput(?int $value): string
{
    return $value === null ? '' : " {$value} ";
}

function startFakeCluster(?array &$calls, array $deployments, array $pausedAnnotations = []): void
{
    $calls = [];
    $items = [];
    foreach ($deployments as $name => $replicas) {
        $items[] = ['metadata' => ['name' => $name], 'spec' => ['replicas' => $replicas]];
    }
    $listJson = json_encode(['items' => $items]);

    Process::fake(function ($process) use (&$calls, $listJson, $pausedAnnotations) {
        $cmd = (string) $process->command;

        if (str_contains($cmd, 'get deployments') && str_contains($cmd, '-o json')) {
            return Process::result(output: $listJson);
        }

        if (str_contains($cmd, 'annotations.larakube\.app/paused-replicas')) {
            foreach ($pausedAnnotations as $name => $value) {
                if (str_contains($cmd, "deployment/{$name} ")) {
                    return Process::result(output: fakeStartReplicaOutput($value));
                }
            }

            return Process::result(output: fakeStartReplicaOutput(null));
        }

        if (str_contains($cmd, ' annotate deployment ') || str_contains($cmd, ' scale deployment/')) {
            $calls[] = $cmd;

            return Process::result(output: 'ok');
        }

        return Process::result(output: '');
    });
}

function startTestProject(string $tempDir): void
{
    (new ConfigData(name: 'test-app'))->saveToFile($tempDir);
}

test('start restores each deployment to its remembered replica count and clears the marker', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    startTestProject($tempDir);
    startFakeCluster($calls, ['web' => 0, 'worker' => 0], ['web' => 3, 'worker' => null]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('start local')->assertExitCode(0);

        expect($calls)->toHaveCount(4)
            ->and($calls[0])->toContain('scale deployment/web')
            ->and($calls[0])->toContain('--replicas=3')
            ->and($calls[1])->toContain('annotate deployment web')
            ->and($calls[1])->toContain('larakube.app/paused-replicas-')
            ->and($calls[2])->toContain('scale deployment/worker')
            ->and($calls[2])->toContain('--replicas=1')
            ->and($calls[3])->toContain('annotate deployment worker')
            ->and($calls[3])->toContain('larakube.app/paused-replicas-');
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('start is a no-op for a deployment already running', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    startTestProject($tempDir);
    startFakeCluster($calls, ['web' => 0, 'already-running' => 2], ['web' => 3]);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('start local')->assertExitCode(0);

        expect($calls)->toHaveCount(2)
            ->and(collect($calls)->filter(fn (string $c) => str_contains($c, 'already-running')))->toBeEmpty();
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});
