<?php

use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

/**
 * withSpin() renders a tick or a cross from its callback's return value read
 * as a boolean. Our callbacks hand back two things that report failure the
 * other way round — a process exit code (0 is success) and a ProcessResult
 * (always truthy) — so both used to render a tick over a failed step.
 */
function spinner(): object
{
    return new class
    {
        use LaraKubeOutput;

        /** @var list<bool> */
        public array $rendered = [];

        public function spin(callable $callback): bool
        {
            return $this->withSpin('doing a thing...', $callback);
        }

        /** Stands in for Laravel Zero's task(), recording what it was told. */
        public function task(string $title, callable $task): bool
        {
            $ok = (bool) $task();
            $this->rendered[] = $ok;

            return $ok;
        }
    };
}

test('a non-zero exit code is a failure, not a tick', function (): void {
    $spinner = spinner();

    expect($spinner->spin(fn () => 0))->toBeTrue()
        ->and($spinner->spin(fn () => 1))->toBeFalse()
        ->and($spinner->spin(fn () => 137))->toBeFalse()
        ->and($spinner->rendered)->toBe([true, false, false]);
});

test('a failed ProcessResult is a failure, not a tick', function (): void {
    Process::fake([
        'good*' => Process::result(output: 'fine'),
        'bad*' => Process::result(output: '', exitCode: 1),
    ]);

    $spinner = spinner();

    expect($spinner->spin(fn () => Process::run('good thing')))->toBeTrue()
        ->and($spinner->spin(fn () => Process::run('bad thing')))->toBeFalse();
});

test('a callback that returns a plain bool or nothing is untouched', function (): void {
    $spinner = spinner();

    expect($spinner->spin(fn () => true))->toBeTrue()
        ->and($spinner->spin(fn () => false))->toBeFalse()
        ->and($spinner->spin(function (): void {}))->toBeFalse();
});
