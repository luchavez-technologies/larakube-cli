<?php

namespace App\Commands\Workspace;

use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\TargetsWorkspaceServer;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/** The workspaces on a server, and whether each is running, suspended or still starting. */
class WorkspaceListCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, TargetsWorkspaceServer;

    protected $signature = 'workspace:list
        {--stack= : The server to list}
        {--context= : A kube-context instead of a server}
        {--reveal : Include each editor password}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the development workspaces on a server';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $server = $this->workspaceServer();

        if ($server === null) {
            return $this->finish(false, [], 'No server to list.');
        }

        $kubectl = $this->workspaceKubectl($server['context']);
        $result = $kubectl->raw(['get', 'namespace', '-l', 'larakube-workspace', '-o', 'json', '--request-timeout=15s']);

        if (! $result->ok) {
            return $this->finish(false, [], "Could not reach the server's Kubernetes ({$server['context']}).");
        }

        $workspaces = [];

        foreach ($result->json()['items'] ?? [] as $namespace) {
            $name = (string) ($namespace['metadata']['labels']['larakube-workspace'] ?? '');
            $notes = $namespace['metadata']['annotations'] ?? [];
            $ns = WorkspaceSpec::namespaceFor($name);
            $deployment = $kubectl->raw(['get', 'deployment', 'workspace', '-n', $ns, '-o', 'json', '--ignore-not-found'])->json();
            $wanted = (int) ($deployment['spec']['replicas'] ?? 0);
            $ready = (int) ($deployment['status']['readyReplicas'] ?? 0);

            $workspaces[] = [
                'name' => $name,
                'namespace' => $ns,
                'repo' => (string) ($notes['larakube.dev/workspace-repo'] ?? ''),
                'branch' => (string) ($notes['larakube.dev/workspace-branch'] ?? ''),
                'size' => (string) ($notes['larakube.dev/workspace-size'] ?? ''),
                'status' => match (true) {
                    $wanted === 0 => 'suspended',
                    $ready > 0 => 'running',
                    default => 'starting',
                },
                'publicKey' => trim((string) $kubectl->secretValue($ns, WorkspaceSpec::SECRET, 'deploy-key.pub')),
                ...($this->flag('reveal') ? ['password' => $kubectl->secretValue($ns, WorkspaceSpec::SECRET, 'password')] : []),
            ];
        }

        return $this->finish(true, $workspaces);
    }

    /** @param  list<array<string, mixed>>  $workspaces */
    private function finish(bool $ok, array $workspaces, ?string $error = null): int
    {
        if ($this->flag('json')) {
            $this->jsonOutput(['success' => $ok, 'workspaces' => $workspaces, ...($error !== null ? ['error' => $error] : [])]);

            return $ok ? 0 : 1;
        }

        if (! $ok) {
            $this->laraKubeError((string) $error);

            return 1;
        }

        if ($workspaces === []) {
            $this->laraKubeInfo('No workspaces on this server yet.');

            return 0;
        }

        table(
            headers: ['Name', 'Status', 'Size', 'Branch', 'Repository'],
            rows: array_map(fn (array $w): array => [$w['name'], $w['status'], $w['size'], $w['branch'], $w['repo']], $workspaces),
        );

        return 0;
    }
}
