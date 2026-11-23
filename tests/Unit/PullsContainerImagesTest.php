<?php

/**
 * Pulling a builder image: progress is shown, and a pull that stalls stops the
 * command with a message naming the likely cause instead of hanging forever.
 */

use App\Traits\PullsContainerImages;
use App\Traits\ResolvesContainerRuntime;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

function pullsContainerImagesHarness(): object
{
    return new class
    {
        use PullsContainerImages, ResolvesContainerRuntime;

        public function pull(string $image): Illuminate\Contracts\Process\ProcessResult
        {
            return $this->pullImage($image);
        }
    };
}

test('a pull is bounded by a stall limit and an overall limit', function (): void {
    $seen = null;
    Process::fake(['*pull*' => function (PendingProcess $process) use (&$seen) {
        $seen = $process;

        return Process::result(output: 'Status: Downloaded newer image');
    }]);

    $result = pullsContainerImagesHarness()->pull('ghcr.io/example/builder:8.5');

    expect($result->successful())->toBeTrue()
        ->and($seen->command)->toContain("pull 'ghcr.io/example/builder:8.5'")
        ->and($seen->idleTimeout)->toBe(300)
        ->and($seen->timeout)->toBe(1200);
});

test('a failed pull comes back to the caller instead of stopping the command', function (): void {
    Process::fake(['*pull*' => Process::result(errorOutput: 'manifest unknown', exitCode: 1)]);

    expect(pullsContainerImagesHarness()->pull('ghcr.io/example/builder:9.9')->failed())->toBeTrue();
});

test('a stalled pull stops with a message naming the container runtime network', function (): void {
    Process::fake(['*pull*' => function (): never {
        $process = new SymfonyProcess(['true']);

        throw new ProcessTimedOutException(
            new SymfonyProcessTimedOutException($process, SymfonyProcessTimedOutException::TYPE_IDLE),
            new ProcessResult($process),
        );
    }]);

    expect(fn () => pullsContainerImagesHarness()->pull('ghcr.io/example/builder:8.5'))
        ->toThrow(RuntimeException::class, 'Pulling ghcr.io/example/builder:8.5 stopped making progress');
});

test('progress keeps layer states that mean movement and drops the per-layer chatter', function (): void {
    $lines = [
        '8.5: Pulling from luchavez-technologies/larakube-builder/php',
        'a1b2c3: Pulling fs layer',
        'a1b2c3: Waiting',
        'a1b2c3: Verifying Checksum',
        'a1b2c3: Download complete',
        'a1b2c3: Pull complete',
        'd4e5f6: Already exists',
        '',
        'Copying blob sha256:abc done',
        'Status: Downloaded newer image for ghcr.io/example/builder:8.5',
    ];

    expect(pullsContainerImagesHarness()::pullProgressLines($lines))->toBe([
        '8.5: Pulling from luchavez-technologies/larakube-builder/php',
        'a1b2c3: Download complete',
        'a1b2c3: Pull complete',
        'd4e5f6: Already exists',
        'Copying blob sha256:abc done',
        'Status: Downloaded newer image for ghcr.io/example/builder:8.5',
    ]);
});
