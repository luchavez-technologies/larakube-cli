<?php

/**
 * runTofu's streaming path must never tie tofu's life to larakube's: a killed
 * parent used to close tofu's pipes mid-apply, tofu died of SIGPIPE before
 * persisting state, and the resources it had already requested were left
 * running but invisible to `cloud:destroy`. These run a REAL stub `tofu`
 * because a faked Process never exercises pipes or signals.
 */

use App\Facades\State;
use App\Support\Os;
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

test('on Windows, tofu output still reaches the user without the POSIX exec/redirect wrapper', function (): void {
    // `exec '.$command.' > log 2>&1` isn't understood by cmd.exe — the
    // Windows branch streams tofu's output into the log file itself via
    // Process's own output callback instead. Still runs this stub as a real
    // subprocess (the test machine is POSIX regardless of the faked OS), so
    // this proves the callback-based path actually captures output, not
    // just that it compiles.
    Os::setForcedFamily('Windows');

    try {
        $directory = TemporaryDirectory::make();
        $bin = tofuInterruptStub($directory, <<<'SH'
echo "google_compute_instance.larakube: Creating..."
echo "Apply complete! Resources: 4 added, 0 changed, 0 destroyed."
SH);

        ob_start();
        $result = tofuInterruptRunner()->apply($bin, 'interrupt-stack-windows');
        $printed = (string) ob_get_clean();

        expect($result['code'])->toBe(0)
            ->and($printed)->toContain('Creating...')
            ->toContain('Apply complete!');

        $directory->delete();
    } finally {
        Os::setForcedFamily(null);
    }
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
    $path = $directory->path().'/tofu';
    $php = PHP_BINARY;
    file_put_contents($path, <<<PHP
#!{$php}
<?php
pcntl_async_signals(true);
pcntl_signal(SIGINT, function (): void {
    echo "Interrupt received. Gracefully shutting down...\\n";
    usleep(50_000);
    echo "state saved\\n";
    exit(1);
});
posix_kill((int) getenv('LARAKUBE_TEST_PARENT_PID'), SIGTERM);
for (\$i = 0; \$i < 100; \$i++) {
    usleep(50_000);
}
echo "never interrupted\\n";
exit(0);
PHP);
    chmod($path, 0755);
    $bin = ['path' => $path, 'isOpenTofu' => true];
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
})->skip(! function_exists('pcntl_signal') || ! function_exists('posix_kill'), 'pcntl/posix is not available in this PHP build');
