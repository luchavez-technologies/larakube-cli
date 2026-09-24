<?php

namespace App\Commands\Github;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

class GhaSwitchCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'gha:switch';

    protected $description = 'Switch between GitHub accounts';

    public function handle()
    {
        $this->renderHeader();

        $this->laraKubeInfo('Switching GitHub accounts...');

        $gh = $this->getGhCommand(interactive: true);
        $this->runInteractive("{$gh} auth switch");

        return 0;
    }
}
