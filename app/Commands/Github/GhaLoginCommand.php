<?php

namespace App\Commands\Github;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

class GhaLoginCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'gha:login';

    protected $description = 'Authenticate with GitHub using the official CLI';

    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeInfo('Launching GitHub CLI authentication wizard...');

        // Request write:packages up front so the same login can PUSH images to
        // GHCR (cloud:deploy) — not just manage Actions secrets. write:packages
        // also grants pull, so the GHCR pull secret works too. Zero local deps:
        // getGhCommand runs the official CLI in Docker when gh isn't installed.
        $gh = $this->getGhCommand(interactive: true);
        $this->runInteractive("{$gh} auth login --scopes write:packages");

        return 0;
    }
}
