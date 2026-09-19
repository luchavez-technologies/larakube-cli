<?php

namespace App\Commands\Traefik;

use App\Services\Kubectl;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class LogsCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'traefik:logs';

    /**
     * The console command description.
     */
    protected $description = 'Tail the logs for the Traefik Ingress Controller';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Tailing Traefik Ingress logs...');

        passthru(Kubectl::current()->prefix().' logs -f deployment/traefik -n traefik');

        return 0;
    }
}
