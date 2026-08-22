<?php

namespace App\Traits;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Support\Sleep;

/**
 * Shared by every trait that reaches a ClusterIP-only Service (no Ingress)
 * through a `kubectl port-forward` tunnel — OpenBao (InteractsWithSecrets)
 * and Stalwart's JMAP endpoint (InteractsWithStalwartApi) as of this trait's
 * introduction.
 */
trait PortForwardsToCluster
{
    /**
     * Poll a local TCP port until something accepts a connection there.
     *
     * `kubectl port-forward` returns immediately but the listener appears
     * asynchronously, and how long that takes scales with the distance to the
     * API server: near-instant against a local cluster, ~2-3s against a remote
     * one. Polling makes the wait proportional instead of guessed.
     */
    protected function awaitLocalPort(int $port, ?InvokedProcess $tunnel = null, float $timeoutSeconds = 15.0): bool
    {
        // Stop before ever touching a real socket when the tunnel is faked
        // (Process::fake() is active) — the ORIGINAL check order called a
        // real fsockopen() first and only fell back to this afterwards,
        // which meant an unrelated real service that happened to be
        // listening on this call's random port (30100-31100 — a wide
        // enough range that a busy dev machine running many local services,
        // or several `pest --parallel` workers each independently picking
        // a port, can and does collide with it) would make awaitLocalPort()
        // return true via a REAL connection instead of the fake short-
        // circuit — and the caller then makes a REAL, entirely unfaked HTTP
        // request to that port (confirmed live: this is exactly what
        // intermittently flipped SecretsUnwireCommandTest's expected output
        // under `pest --parallel` — staticRoleExists() got back a real
        // response instead of the expected null-because-unreachable).
        if ($tunnel instanceof FakeInvokedProcess) {
            return true;
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            // Stop as soon as the tunnel itself is gone — waiting out the full
            // timeout on a dead port-forward buys nothing.
            if ($tunnel !== null && ! $tunnel->running()) {
                return false;
            }

            Sleep::usleep(200_000);
        }

        return false;
    }
}
