<?php

/**
 * runTofu's streaming path must never tie tofu's life to larakube's: a killed
 * parent used to close tofu's pipes mid-apply, tofu died of SIGPIPE before
 * persisting state, and the resources it had already requested were left
 * running but invisible to `cloud:destroy`. These run a REAL stub `tofu`
 * because a faked Process never exercises pipes or signals.
 */

use App\Facades\State;
use App\Traits\InteractsWithOpenTofu;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function tofuInterruptRunner(): object
{
    return new class
    {
        use InteractsWithOpenTofu;

        public function apply(array $bin, string $stack, array $env = []): array
        {
            return $this->runTofu($bin, $stack, 'apply', ['-input=false'], $env);
        }
    };
}

/**
 * @return array{path: string, isOpenTofu: bool}
 */
function tofuInterruptStub(TemporaryDirectory $directory, string $script): array
{
    $path = $directory->path().'/tofu';
    file_put_contents($path, "#!/bin/sh\n".$script."\n");
    chmod($path, 0755);

    return ['path' => $path, 'isOpenTofu' => true];
}

test('tofu output reaches the user without tofu ever writing into a pipe', function (): void {
    $directory = TemporaryDirectory::make();
    $bin = tofuInterruptStub($directory, <<<'SH'
if [ -p /dev/stdout ]; then echo "stdout is a pipe"; exit 3; fi
echo "google_compute_instance.larakube: Creating..."
sleep 0.3
echo "Apply complete! Resources: 4 added, 0 changed, 0 destroyed."
SH);

    ob_start();
    $result = tofuInterruptRunner()->apply($bin, 'interrupt-stack');
    $printed = (string) ob_get_clean();

    expect($result['code'])->toBe(0)
        ->and($printed)->toContain('Creating...')
        ->toContain('Apply complete!')
        ->not->toContain('stdout is a pipe');

    $directory->delete();
});

test('tofu exit codes pass through unchanged', function (): void {
    $directory = TemporaryDirectory::make();
    $bin = tofuInterruptStub($directory, 'echo "Error: quota exceeded"; exit 1');

    ob_start();
    $result = tofuInterruptRunner()->apply($bin, 'interrupt-stack');
    ob_end_clean();

    expect($result['code'])->toBe(1);
});

test('under --json the tofu stream stays off stdout', function (): void {
    State::setJsonMode(true);
    $directory = TemporaryDirectory::make();
    $bin = tofuInterruptStub($directory, 'echo "Plan: 4 to add"');

    ob_start();
    tofuInterruptRunner()->apply($bin, 'interrupt-stack');

    expect((string) ob_get_clean())->toBeEmpty();

    $directory->delete();
});

test('SIGTERM becomes one graceful SIGINT to tofu, which is waited for', function (): void {
    $directory = TemporaryDirectory::make();
    $bin = tofuInterruptStub($directory, <<<'SH'
trap 'echo "Interrupt received. Gracefully shutting down..."; sleep 0.2; echo "state saved"; exit 1' INT
kill -TERM "$LARAKUBE_TEST_PARENT_PID"
i=0
while [ $i -lt 100 ]; do sleep 0.05; i=$((i+1)); done
echo "never interrupted"
exit 0
SH);
    $originalHandler = pcntl_signal_get_handler(SIGTERM);

    ob_start();
    $result = tofuInterruptRunner()->apply($bin, 'interrupt-stack', ['LARAKUBE_TEST_PARENT_PID' => (string) getmypid()]);
    $printed = (string) ob_get_clean();

    expect($printed)->toContain('Stopping — waiting for OpenTofu')
        ->toContain('Gracefully shutting down')
        ->toContain('state saved')
        ->not->toContain('never interrupted')
        ->and($result['code'])->not->toBe(0)
        ->and(State::lastError())->toContain('cloud:destroy')
        ->and(pcntl_signal_get_handler(SIGTERM))->toBe($originalHandler);

    $directory->delete();
})->skip(! function_exists('pcntl_signal'), 'pcntl is not available in this PHP build');
