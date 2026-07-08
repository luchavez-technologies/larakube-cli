<?php

namespace App\Commands\Traefik;

use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class RestartCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'traefik:restart';

    /**
     * The console command description.
     */
    protected $description = 'Force a rollout restart of the Traefik Ingress Controller';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Restarting Traefik Ingress Controller...');

        $this->withSpin('Executing rollout restart...', function () {
            Process::run('kubectl rollout restart deployment/traefik -n traefik');

            return true;
        });

        $this->laraKubeInfo('✅ Traefik restart initiated.');

        return 0;
    }
}
