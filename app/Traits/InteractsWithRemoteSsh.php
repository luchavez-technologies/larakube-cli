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
     * Poll the SSH endpoint until it answers (or we give up). A freshly created
     * droplet needs ~30–60s before sshd accepts connections, so the OpenTofu
     * flow calls this between `tofu apply` and the provisioning steps; a
     * post-reboot hardening run (rebootIfRequired()) uses it too, to know when
     * the box is back.
     *
     * @param  int  $maxAttempts  attempts at $delay-second intervals (default ~2.5min)
     */
    protected function waitForSsh($user, $ip, $port, $keyPath, int $maxAttempts = 30, int $delay = 5): bool
    {
        $this->laraKubeInfo("Waiting for SSH on {$user}@{$ip}:{$port}...");

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->testSsh($user, $ip, $port, $keyPath)) {
                $this->laraKubeInfo('SSH is up.');

                return true;
            }

            if ($attempt % 3 === 0) {
                $this->line('  ⏳ Still waiting for sshd... ('.($attempt * $delay).'s)');
            }
            sleep($delay);
        }

        return false;
    }

    /**
     * If a system upgrade pulled in a kernel/library update that needs a
     * reboot to fully take effect (Ubuntu/Debian flag this via the presence
     * of /var/run/reboot-required), reboot now and wait for the box to come
     * back — instead of leaving a patched-on-disk-but-not-running kernel in
     * place until unattended-upgrades' own scheduled reboot (hours/days later)
     * or a human happens to notice. Shared by the initial provisioning
     * pipeline (ProvisionsK3sNode::hardenServer(), where it also means k3s
     * installs onto the kernel it'll actually keep running on) and a later
     * standalone `cloud:harden` re-run, which does its own system upgrade too.
     */
    protected function rebootIfRequired($user, $ip, $port, $keyPath): void
    {
        $check = "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} 'test -f /var/run/reboot-required'";
        if (! Process::run($check)->successful()) {
            return; // no reboot needed
        }

        $this->laraKubeInfo('A reboot is required to finish applying system updates — rebooting now...');

        $sudo = $user !== 'root' ? 'sudo ' : '';
        $rebootCmd = "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} '{$sudo}reboot'";
        // The reboot kills this SSH session immediately — a non-zero exit or
        // timeout here is the EXPECTED result, not a failure to check for.
        Process::timeout(10)->run($rebootCmd);

        sleep(5); // give the box a moment to actually go down before polling

        if (! $this->waitForSsh($user, $ip, $port, $keyPath)) {
            $this->laraKubeWarn('Server did not come back online after reboot — check it manually before continuing.');

            return;
        }

        $this->laraKubeInfo('Server is back online after reboot.');
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
     *
     * Returns whether the remote script actually succeeded — callers MUST
     * check this rather than assume it, and MUST NOT print a success message
     * unconditionally afterward. Scripts built by this codebase start with
     * `set -e`, so a failed step aborts the rest silently from the caller's
     * point of view unless this return value is checked.
     */
    protected function runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand): bool
    {
        $sudo = $user !== 'root' ? 'sudo ' : '';
        $fullCommand = $sudo.'bash -c '.escapeshellarg($remoteCommand);
        $sshCommand = "ssh -i {$keyPath} -p {$port} {$user}@{$ip} ".escapeshellarg($fullCommand);

        $result = Process::forever()->run($sshCommand, function (string $type, string $output) {
            echo $output;
        });

        return $result->successful();
    }
}
