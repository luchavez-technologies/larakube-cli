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
use LaravelZero\Framework\Commands\Command;

/** Suspend and resume differ only in the replica count; the volume (repo, vendor, extensions) is kept either way. */
abstract class AbstractWorkspaceScaleCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, TargetsWorkspaceServer;

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

        $namespace = WorkspaceSpec::namespaceFor($name);
        $result = $kubectl->raw(['scale', 'deployment/workspace', '-n', $namespace, '--replicas='.$this->replicas()]);

        if (! $result->ok) {
            return $this->finish(false, trim($result->error));
        }

        if ($this->replicas() > 0) {
            $kubectl->rolloutStatus($namespace, 'workspace', 120);
        }

        $this->laraKubeInfo($this->replicas() > 0 ? "✅ Workspace '{$name}' resumed." : "✅ Workspace '{$name}' suspended. Its files are kept.");

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'name' => $name]);
        }

        return 0;
    }

    abstract protected function replicas(): int;

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
