<?php

namespace App\Commands;

use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class UntrustCommand extends Command
{
    use InteractsWithTrust, LaraKubeOutput;

    protected $signature = 'trust:remove';

    protected $description = 'Remove the LaraKube Local CA from your system trust store';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local HTTPS Cleanup');

        if (file_exists('/.dockerenv') && ! $this->isWsl()) {
            $this->error('  ✖ This command must be run directly on your HOST (or WSL2) machine.');

            return 1;
        }

        $this->removeCaFromKeychain();

        $this->line('');
        $this->laraKubeInfo('✅ LaraKube Local CA removed. Restart your browser to apply.');

        return 0;
    }
}
