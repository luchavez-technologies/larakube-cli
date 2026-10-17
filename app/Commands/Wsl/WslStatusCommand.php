<?php

namespace App\Commands\Wsl;

use App\Services\WslBridge;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class WslStatusCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'wsl:status';

    protected $description = 'Display the status and networking configuration of the WSL2 environment';

    public function handle(WslBridge $bridge): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('WSL2 Environment Status');

        $installed = $bridge->isWslInstalled();
        $distroName = $bridge->distroName();
        $distroExists = $installed && $bridge->distroExists();
        $distroRunning = $distroExists && $bridge->distroIsRunning();
        $mirroredRequested = $bridge->mirroredNetworkingRequested();
        $mirroredActive = $distroRunning && $bridge->mirroredNetworkingActive();
        $restartPending = $bridge->mirroredRestartPending();

        $running = $bridge->runningDistros();
        $runningList = $running !== [] ? implode(', ', $running) : 'None';

        table(
            headers: ['Property', 'Status'],
            rows: [
                ['WSL2 Installed', $installed ? '✓ Yes' : '✗ No'],
                ['Target Distro', $distroName.($distroExists ? ' (installed)' : ' (missing)')],
                ['Distro Running', $distroRunning ? '✓ Yes' : 'Stopped'],
                ['Running Distros', $runningList],
                ['Mirrored Configured in ~/.wslconfig', $mirroredRequested ? '✓ Yes' : 'No'],
                ['Mirrored Networking Active', $mirroredActive ? '✓ Active' : ($restartPending ? '⚠ Pending Restart' : 'Inactive')],
            ],
        );

        if ($restartPending) {
            $this->newLine();
            $this->laraKubeWarn('WSL mirrored networking is configured in ~/.wslconfig but not active yet.');
            $this->line('  Run <fg=cyan>larakube wsl:restart</> to apply it immediately.');
        }

        return 0;
    }
}
