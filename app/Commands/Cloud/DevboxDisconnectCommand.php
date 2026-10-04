<?php

namespace App\Commands\Cloud;

use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesDevBoxTunnel;
use App\Traits\ReadsCommandOptions;
use LaravelZero\Framework\Commands\Command;

class DevboxDisconnectCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, LaraKubeOutput, ManagesDevBoxTunnel, ReadsCommandOptions;

    protected $signature = 'devbox:disconnect
        {--stack-name= : The dev box whose tunnel to close}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Close the SSH tunnel to a dev box\'s cluster (the kube-context stays, for next time)';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $stack = $this->getGlobalConfig()->findStack((string) $this->flag('stack-name'));

        if ($stack === null || $stack->role !== 'dev') {
            $this->laraKubeError('That is not a dev box this computer made.');

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => false, 'error' => 'That is not a dev box this computer made.']);
            }

            return 1;
        }

        $this->stopTunnel($stack);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'disconnected' => true]);
        } else {
            $this->laraKubeInfo("Tunnel to {$stack->name} closed.");
        }

        return 0;
    }
}
