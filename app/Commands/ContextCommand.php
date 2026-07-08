<?php

namespace App\Commands;

use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ContextCommand extends Command
{
    use InteractsWithClusterContext, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'context {name? : The name of the context to switch to}';

    /**
     * The console command description.
     */
    protected $description = 'Switch between Kubernetes contexts easily';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $currentContext = $this->kubectlCurrentContext();
        $targetContext = $this->argument('name');

        if (! $targetContext) {
            $targetContext = $this->askForClusterContext();
        }

        if (! $targetContext) {
            $this->laraKubeError('No Kubernetes contexts found or selection cancelled.');

            return 1;
        }

        if ($targetContext === $currentContext) {
            $this->laraKubeInfo("Already on context: <fg=cyan;options=bold>{$targetContext}</>");

            return 0;
        }

        if ($this->switchClusterContext($targetContext)) {
            $this->laraKubeInfo("Switched to context: <fg=cyan;options=bold>{$targetContext}</>");
        } else {
            $this->laraKubeError('Failed to switch context.');

            return 1;
        }

        return 0;
    }
}
