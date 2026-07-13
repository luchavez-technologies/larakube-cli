<?php

namespace App\Commands\Traefik;

use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithTraefik, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'traefik:setup';

    /**
     * The console command description.
     */
    protected $description = 'Install or upgrade the Traefik Ingress Controller';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->confirmLocalOnlyAction('Traefik + the shared Mailpit companion')) {
            $this->laraKubeInfo('Setup cancelled.');

            return 0;
        }

        if (! $this->setupTraefik()) {
            $this->laraKubeError('Traefik setup failed — see the output above.');

            return 1;
        }

        $this->laraKubeInfo('✅ Traefik setup complete.');

        return 0;
    }
}
