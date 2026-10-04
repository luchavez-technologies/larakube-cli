<?php

namespace App\Traits;

use App\Data\StackData;
use Illuminate\Support\Facades\Process;

/**
 * The SSH tunnel that brings a dev box's cluster API to this computer. The box only opens SSH, so
 * its kubeconfig points at a local port and this keeps a background ssh running to carry it. The
 * tunnel is controlled through an ssh control socket, which makes starting it safe to repeat.
 */
trait ManagesDevBoxTunnel
{
    protected const int TUNNEL_PORT_START = 16443;

    protected function devBoxContextName(string $name): string
    {
        return 'larakube-devbox-'.$name;
    }

    protected function tunnelSocket(string $name): string
    {
        $dir = home_path('.larakube/tunnels');

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir.'/'.$name.'.sock';
    }

    protected function tunnelAlive(StackData $stack): bool
    {
        return Process::run(['ssh', '-S', $this->tunnelSocket($stack->name), '-O', 'check', 'larakube@'.$stack->ip])->successful();
    }

    /** True when nothing on this computer is listening on the port. */
    protected function localPortFree(int $port): bool
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /** The first free port from the start of the range, skipping ports other dev boxes already use. */
    protected function pickTunnelPort(array $takenPorts): int
    {
        for ($port = self::TUNNEL_PORT_START; $port < self::TUNNEL_PORT_START + 200; $port++) {
            if (! in_array($port, $takenPorts, true) && $this->localPortFree($port)) {
                return $port;
            }
        }

        return self::TUNNEL_PORT_START;
    }

    protected function startTunnel(StackData $stack, int $port): bool
    {
        $socket = $this->tunnelSocket($stack->name);
        @unlink($socket);

        // Backgrounds itself once connected; its output is discarded so this does not wait for it.
        $command = 'ssh -f -N -M -S '.escapeshellarg($socket)
            .' -i '.escapeshellarg((string) $stack->sshKey)
            .' -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ConnectTimeout=15'
            ." -L 127.0.0.1:{$port}:127.0.0.1:6443 ".escapeshellarg('larakube@'.$stack->ip).' >/dev/null 2>&1';

        return Process::run(['sh', '-c', $command])->successful();
    }

    protected function stopTunnel(StackData $stack): void
    {
        Process::run(['ssh', '-S', $this->tunnelSocket($stack->name), '-O', 'exit', 'larakube@'.$stack->ip]);
        @unlink($this->tunnelSocket($stack->name));
    }
}
