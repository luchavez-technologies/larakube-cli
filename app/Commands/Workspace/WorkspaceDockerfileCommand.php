<?php

namespace App\Commands\Workspace;

use App\Enums\WorkspaceRuntime;
use App\Services\Workspace\WorkspaceSpec;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use LaravelZero\Framework\Commands\Command;

/** The Dockerfile of a workspace image. The published images are built from exactly this. */
class WorkspaceDockerfileCommand extends Command
{
    use LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'workspace:dockerfile
        {--runtime= : php, node, python, java, dotnet, go or rust}
        {--runtime-version= : The runtime version. Default is the runtime\'s own}';

    protected $description = 'Print the Dockerfile of a workspace image';

    public function handle(): int
    {
        $runtime = WorkspaceRuntime::tryFrom((string) $this->flag('runtime'));

        if ($runtime === null) {
            $this->laraKubeError('Choose a --runtime: '.implode(', ', array_column(WorkspaceRuntime::cases(), 'value')).'.');

            return 1;
        }

        $version = (string) ($this->flag('runtime-version') ?: $runtime->defaultVersion());

        if (! WorkspaceSpec::validVersion($runtime, $version)) {
            $this->laraKubeError("{$runtime->label()} {$version} is not offered. Choose one of: ".implode(', ', $runtime->versions()).'.');

            return 1;
        }

        $this->output->write((new WorkspaceSpec)->dockerfile($runtime, $version));

        return 0;
    }
}
