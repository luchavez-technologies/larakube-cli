<?php

namespace App\Commands\Wsl;

use App\Services\WslBridge;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class WslShutdownCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'wsl:shutdown {--force : Force shutdown without prompting}';

    protected $description = 'Shut down all running WSL2 distributions and terminate the virtual machine';

    public function handle(WslBridge $bridge): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('WSL2 Virtual Machine Shutdown');

        if (! $bridge->isWslInstalled()) {
            $this->laraKubeError('WSL2 is not installed or enabled on this system.');

            return 1;
        }

        $otherDistros = $bridge->otherRunningDistros();

        if ($otherDistros !== [] && ! $this->option('force')) {
            $names = implode(', ', $otherDistros);
            $this->laraKubeWarn("Other WSL distributions are currently running: {$names}");

            if (! confirm('Do you want to shut down WSL anyway?', default: false)) {
                $this->laraKubeInfo('WSL shutdown cancelled.');

                return 0;
            }
        }

        $this->laraKubeInfo('Shutting down WSL virtual machine...');

        if (! $bridge->shutdown()) {
            $this->laraKubeError('Failed to shut down WSL.');

            return 1;
        }

        $this->laraKubeInfo('✅ WSL shut down successfully.');

        return 0;
    }
}
