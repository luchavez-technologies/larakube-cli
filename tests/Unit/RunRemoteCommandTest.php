<?php

/**
 * runRemoteCommand() used to return void and never check the SSH command's
 * exit code — every caller (hardenServer(), installK3s(), CloudHardenCommand,
 * …) printed an unconditional "✅ success" message regardless of whether the
 * remote script actually completed. A remote script aborting partway through
 * (e.g. cloud-init holding the dpkg lock on a freshly booted droplet, racing
 * our own apt-get) would silently leave the box unhardened while the CLI
 * reported success. This locks in the fix: the return value must reflect the
 * SSH command's real exit code.
 */

use App\Facades\State;
use App\Traits\InteractsWithRemoteSsh;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function remoteSshRunner(): object
{
    return new class
    {
        use InteractsWithRemoteSsh;

        public function run($user, $ip, $port, $keyPath, $remoteCommand): bool
        {
            return $this->runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand);
        }
    };
}

test('runRemoteCommand returns true when the remote script succeeds', function (): void {
    Process::fake(['ssh *' => Process::result(exitCode: 0)]);

    expect(remoteSshRunner()->run('root', '1.2.3.4', '22', '/key', 'echo ok'))->toBeTrue();
});

test('runRemoteCommand returns false when the remote script fails partway through', function (): void {
    Process::fake(['ssh *' => Process::result(output: 'E: Could not get lock', exitCode: 1)]);

    expect(remoteSshRunner()->run('root', '1.2.3.4', '22', '/key', 'apt-get upgrade -y'))->toBeFalse();
});

/**
 * Run $callback with a stub `ssh` first on PATH that prints $output. A faked
 * Process::run() never invokes the streaming callback, so these tests need a
 * real child process to see where its output lands.
 */
function withStubSsh(string $output, Closure $callback): mixed
{
    $directory = TemporaryDirectory::make();
    file_put_contents($directory->path().'/ssh', "#!/bin/sh\nprintf '%s' ".escapeshellarg($output)."\n");
    chmod($directory->path().'/ssh', 0755);

    $originalPath = (string) getenv('PATH');
    putenv('PATH='.$directory->path().PATH_SEPARATOR.$originalPath);

    try {
        return $callback();
    } finally {
        putenv('PATH='.$originalPath);
        $directory->delete();
    }
}

test('runRemoteCommand streams remote output to stdout in normal mode', function (): void {
    $printed = withStubSsh("Hit:1 noble InRelease\n", function (): string {
        ob_start();
        remoteSshRunner()->run('root', '1.2.3.4', '22', '/key', 'apt-get update');

        return (string) ob_get_clean();
    });

    expect($printed)->toContain('Hit:1');
});

test('runRemoteCommand keeps stdout clean under --json so the result line stays parseable', function (): void {
    State::setJsonMode(true);

    $printed = withStubSsh("Hit:1 noble InRelease\n", function (): string {
        ob_start();
        remoteSshRunner()->run('root', '1.2.3.4', '22', '/key', 'apt-get update');

        return (string) ob_get_clean();
    });

    expect($printed)->toBe('');
});
