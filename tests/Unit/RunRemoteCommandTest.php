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

use App\Traits\InteractsWithRemoteSsh;
use Illuminate\Support\Facades\Process;

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
