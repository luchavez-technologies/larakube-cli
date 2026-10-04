<?php

namespace App\Commands\Workspace;

use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\TargetsWorkspaceServer;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

/**
 * Reaches a workspace's editor through a tunnel from this computer to the pod, so
 * it never needs a public address. Runs until stopped (Ctrl+C).
 */
class WorkspaceOpenCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, TargetsWorkspaceServer;

    protected $signature = 'workspace:open
        {--stack= : The server the workspace is on}
        {--context= : A kube-context instead of a server}
        {--name= : The workspace to open}
        {--port= : The local port to use. Omit to pick a free one}
        {--json : Print one JSON line with the address, then keep the tunnel open}';

    protected $description = 'Open a tunnel to a workspace editor and print its address';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $name = $this->requireWorkspaceName();
            $server = $this->workspaceServer();
        } catch (InvalidArgumentException $e) {
            return $this->failWith($e->getMessage());
        }

        if ($server === null) {
            return $this->failWith('No server to act on.');
        }

        $kubectl = $this->workspaceKubectl($server['context']);

        if ($this->findWorkspace($kubectl, $name) === null) {
            return $this->failWith("No workspace named '{$name}' on that server.", reported: true);
        }

        $namespace = WorkspaceSpec::namespaceFor($name);

        if (! $kubectl->rolloutStatus($namespace, 'workspace', 60)->ok) {
            return $this->failWith("Workspace '{$name}' is not running. Resume it first.");
        }

        $port = (int) ($this->flag('port') ?: $this->freePort());
        $url = "http://127.0.0.1:{$port}/";

        $this->laraKubeInfo("Editor: {$url}");
        $this->line('  Password: '.$kubectl->secretValue($namespace, WorkspaceSpec::SECRET, 'password'));
        $this->line('  Press Ctrl+C to close the tunnel.');

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'name' => $name, 'url' => $url, 'port' => $port]);
        }

        Process::forever()->run($kubectl->prefix()." -n {$namespace} port-forward svc/workspace {$port}:8080");

        return 0;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No free local port.');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    private function failWith(string $message, bool $reported = false): int
    {
        if (! $reported) {
            $this->laraKubeError($message);
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        }

        return 1;
    }
}
