<?php

namespace App\Commands\Workspace;

use App\Services\Workspace\WorkspaceSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\TargetsWorkspaceServer;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

/** Deletes a workspace and its volume. Anything not pushed to the repository is lost. */
class WorkspaceRemoveCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, TargetsWorkspaceServer;

    protected $signature = 'workspace:remove
        {--stack= : The server the workspace is on}
        {--context= : A kube-context instead of a server}
        {--name= : The workspace to delete}
        {--force : Skip the confirmation}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Delete a workspace and its files';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $name = $this->requireWorkspaceName();
            $server = $this->workspaceServer();
        } catch (InvalidArgumentException $e) {
            return $this->finish(false, $e->getMessage());
        }

        if ($server === null) {
            return $this->finish(false, 'No server to act on.');
        }

        $kubectl = $this->workspaceKubectl($server['context']);

        if ($this->findWorkspace($kubectl, $name) === null) {
            return $this->finish(false, "No workspace named '{$name}' on that server.", reported: true);
        }

        if (! $this->flag('force') && ! confirm("Delete workspace '{$name}'? Work that is not pushed is lost.", default: false)) {
            $this->laraKubeInfo('Left as it is.');

            return 0;
        }

        $result = $kubectl->raw(['delete', 'namespace', WorkspaceSpec::namespaceFor($name), '--ignore-not-found', '--wait=false']);

        if (! $result->ok) {
            return $this->finish(false, trim($result->error));
        }

        $this->laraKubeInfo("✅ Workspace '{$name}' deleted.");

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'name' => $name]);
        }

        return 0;
    }

    private function finish(bool $ok, string $error, bool $reported = false): int
    {
        if (! $reported) {
            $this->laraKubeError($error);
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => false, 'error' => $error]);
        }

        return 1;
    }
}
