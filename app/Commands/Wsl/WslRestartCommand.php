<?php

namespace App\Commands\Wsl;

use App\Services\WslBridge;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class WslRestartCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'wsl:restart {--force : Force shutdown even if other WSL distributions are running}';

    protected $description = 'Restart the WSL2 virtual machine to apply networking or system configuration changes';

    public function handle(WslBridge $bridge): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('WSL2 Virtual Machine Restart');

        if (! $bridge->isWslInstalled()) {
            $this->laraKubeError('WSL2 is not installed or enabled on this system.');

            return 1;
        }

        $otherDistros = $bridge->otherRunningDistros();

        if ($otherDistros !== [] && ! $this->option('force')) {
            $names = implode(', ', $otherDistros);
            $this->laraKubeWarn("Other WSL distributions are currently running: {$names}");
            $this->line('  <fg=gray>Restarting WSL will stop all running Linux sessions on this computer.</>');

            if (! confirm('Do you want to shut down WSL anyway?', default: false)) {
                $this->laraKubeInfo('WSL restart cancelled.');

                return 0;
            }
        }

        $this->laraKubeInfo('Shutting down WSL virtual machine...');

        if (! $bridge->shutdown()) {
            $this->laraKubeError('Failed to shut down WSL.');

            return 1;
        }

        $this->laraKubeInfo('✅ WSL shut down successfully. The virtual machine will automatically restart on the next command.');

        return 0;
    }
}
