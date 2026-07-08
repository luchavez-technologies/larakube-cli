<?php

/**
 * Tests for InteractsWithRemoteSsh's mechanical, non-interactive checks
 * (BatchMode=yes guarantees these never prompt). runRemoteCommand() streams
 * a remote script live via runStreaming() and is left to a real droplet
 * smoke test — nothing meaningful to assert beyond "a real SSH session ran".
 */

use App\Traits\InteractsWithRemoteSsh;
use Illuminate\Support\Facades\Process;

function remoteSshHelper(): object
{
    return new class
    {
        use InteractsWithRemoteSsh;

        public function ssh($user, $ip, $port, $keyPath): bool
        {
            return $this->testSsh($user, $ip, $port, $keyPath);
        }

        public function sudo($user, $ip, $port, $keyPath): bool
        {
            return $this->canSudo($user, $ip, $port, $keyPath);
        }
    };
}

test('testSsh is true only on the exact "success" echo', function () {
    Process::fake(["ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i /key -p 22 root@1.2.3.4 'echo success'" => "success\n"]);
    expect(remoteSshHelper()->ssh('root', '1.2.3.4', 22, '/key'))->toBeTrue();

    Process::fake(["ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i /key -p 22 root@1.2.3.4 'echo success'" => Process::result(output: '', exitCode: 255)]);
    expect(remoteSshHelper()->ssh('root', '1.2.3.4', 22, '/key'))->toBeFalse();
});

test('canSudo is true only when the remote sudo -n true prints nothing at all', function () {
    Process::fake(["ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i /key -p 22 larakube@1.2.3.4 'sudo -n true'" => '']);
    expect(remoteSshHelper()->sudo('larakube', '1.2.3.4', 22, '/key'))->toBeTrue();

    Process::fake(["ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i /key -p 22 larakube@1.2.3.4 'sudo -n true'" => Process::result(errorOutput: 'sudo: a password is required', exitCode: 1)]);
    expect(remoteSshHelper()->sudo('larakube', '1.2.3.4', 22, '/key'))->toBeFalse();
});
