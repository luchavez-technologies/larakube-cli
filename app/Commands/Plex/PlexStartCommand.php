<?php

namespace App\Commands\Plex;

use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ScalesCommonsServices;
use App\Traits\TargetsExistingCommons;
use LaravelZero\Framework\Commands\Command;

class PlexStartCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithClusterContext, InteractsWithPlex, LaraKubeOutput, ScalesCommonsServices, TargetsExistingCommons;

    protected $signature = 'plex:start
        {environment? : The environment whose Commons to resume ("local" or a cloud env)}
        {--context= : Target a specific kube-context}
        {--service= : Only this Commons service (e.g. postgres, redis); every one when omitted}
        {--json     : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Resume paused Commons services';

    public function handle(): int
    {
        return $this->scaleCommons(1);
    }
}
