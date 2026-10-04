<?php

namespace App\Commands\Workspace;

use App\Enums\AppFramework;
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
        {--port= : The local port for the editor. Omit to pick a free one}
        {--app-port=* : A local port for one of the app\'s dev ports, as local:remote (for example 8000:8000). Omit to use the app\'s own port numbers when they are free}
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

        $found = $this->findWorkspace($kubectl, $name);

        if ($found === null) {
            return $this->failWith("No workspace named '{$name}' on that server.", reported: true);
        }

        $framework = AppFramework::tryFrom((string) ($found['metadata']['annotations']['larakube.dev/workspace-framework'] ?? '')) ?? AppFramework::LARAVEL;

        $namespace = WorkspaceSpec::namespaceFor($name);

        if (! $kubectl->rolloutStatus($namespace, 'workspace', 60)->ok) {
            return $this->failWith("Workspace '{$name}' is not running. Resume it first.");
        }

        $port = (int) ($this->flag('port') ?: $this->freePort());
        $url = "http://127.0.0.1:{$port}/";
        $apps = $this->appForwards($framework, $port);

        $this->laraKubeInfo("Editor: {$url}");
        foreach ($apps as $app) {
            $this->line("  {$app['name']}: http://127.0.0.1:{$app['local']}/");
        }
        $this->line('  Password: '.$kubectl->secretValue($namespace, WorkspaceSpec::SECRET, 'password'));
        $this->line("  Start the app in the editor's terminal with: {$framework->devCommand()}");
        $this->line('  Press Ctrl+C to close the tunnel.');

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'name' => $name, 'url' => $url, 'port' => $port, 'apps' => array_map(fn (array $app): array => $app + ['url' => "http://127.0.0.1:{$app['local']}/"], $apps)]);
        }

        $forwards = implode(' ', [
            "{$port}:8080",
            ...array_map(fn (array $app): string => "{$app['local']}:{$app['remote']}", $apps),
        ]);

        Process::forever()->run($kubectl->prefix()." -n {$namespace} port-forward svc/workspace {$forwards}");

        return 0;
    }

    /**
     * The app's dev ports and where each one appears on this computer: as asked with
     * --app-port, else the same number when it is free, else a free one.
     *
     * @return list<array{name: string, local: int, remote: int}>
     */
    private function appForwards(AppFramework $framework, int $editorPort): array
    {
        $asked = [];
        foreach ((array) $this->flag('app-port') as $pair) {
            if (preg_match('/^(\d{2,5}):(\d{2,5})$/', (string) $pair, $m) === 1) {
                $asked[(int) $m[2]] = (int) $m[1];
            }
        }

        $taken = [$editorPort];
        $apps = [];

        foreach ($framework->devPorts() as $dev) {
            $local = $asked[$dev['port']] ?? $dev['port'];
            while (in_array($local, $taken, true) || ! $this->portIsFree($local)) {
                $local++;
            }

            $taken[] = $local;
            $apps[] = ['name' => $dev['name'], 'local' => $local, 'remote' => $dev['port']];
        }

        return $apps;
    }

    private function portIsFree(int $port): bool
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

        if (is_resource($connection)) {
            fclose($connection);

            return false;
        }

        return true;
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
