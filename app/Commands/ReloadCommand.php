<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReloadsLongRunningPods;
use LaravelZero\Framework\Commands\Command;

class ReloadCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput, ReloadsLongRunningPods;

    protected $signature = 'reload {--environment=local : The environment to target}';

    protected $description = 'Reload PHP code in long-running pods (Octane workers, Horizon, queues, Reverb)';

    public function handle(): int
    {
        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $this->renderHeader();

        $config = $this->getProjectConfig();
        if (! $config) {
            $this->laraKubeError('Could not load .larakube.json.');

            return 1;
        }

        if (self::reloadTargets($config) === []) {
            $this->laraKubeInfo('Nothing to reload — no Octane/Horizon/queues/Reverb in this project.');

            return 0;
        }

        $this->reloadLongRunningPods($config, (string) $this->option('environment'));

        return 0;
    }
}
