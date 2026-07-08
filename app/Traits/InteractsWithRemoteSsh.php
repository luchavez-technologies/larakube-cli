<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Shared SSH primitives for the cloud:* commands that drive a remote host
 * (provision, harden). Kept in one place so connection + remote-exec behaviour
 * stays identical across them.
 */
trait InteractsWithRemoteSsh
{
    /** Probe the SSH connection with a key, non-interactively. */
    protected function testSsh($user, $ip, $port, $keyPath): bool
    {
        $command = "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} 'echo success'";

        return trim(Process::run($command)->output()) === 'success';
    }

    /**
     * Does this user have *passwordless* sudo on the host? `sudo -n true` exits 0
     * and prints nothing when it works; otherwise it prints a password/permission
     * error to stderr. Used as a lockout guard before we disable remote root login.
     */
    protected function canSudo($user, $ip, $port, $keyPath): bool
    {
        $command = "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} 'sudo -n true'";
        $result = Process::run($command);

        return trim($result->output().$result->errorOutput()) === '';
    }

    /**
     * Run a bash script on the remote host (sudo-wrapped for non-root users),
     * streaming output live (matching passthru()'s user-visible behavior) via
     * the Process facade, so remote provisioning stays fakeable in tests. No
     * timeout — a remote install/hardening script can legitimately run for
     * minutes, and passthru() never had one either.
     */
    protected function runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand): void
    {
        $sudo = $user !== 'root' ? 'sudo ' : '';
        $fullCommand = $sudo.'bash -c '.escapeshellarg($remoteCommand);
        $sshCommand = "ssh -i {$keyPath} -p {$port} {$user}@{$ip} ".escapeshellarg($fullCommand);

        Process::forever()->run($sshCommand, function (string $type, string $output) {
            echo $output;
        });
    }
}
