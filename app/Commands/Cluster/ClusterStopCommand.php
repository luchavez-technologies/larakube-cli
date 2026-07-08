<?php

namespace App\Commands\Cluster;

use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class ClusterStopCommand extends Command
{
    use InteractsWithOs, LaraKubeOutput, StreamsProcessOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cluster:stop';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pause the local LaraKube cluster (stops containers without deleting data)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeInfo('Stopping LaraKube cluster...');

        if (Process::run('which k3d')->successful()) {
            $this->runStreaming('k3d cluster stop larakube');
        } elseif (Process::run('which k3s')->successful() && $this->isLinux()) {
            $this->info('  Detected native k3s. Using systemctl...');
            passthru('sudo systemctl stop k3s');
        } else {
            $this->laraKubeError('No supported cluster engine (k3d or k3s) found.');

            return 1;
        }

        $this->laraKubeInfo('✅ Cluster stopped. Run "larakube cluster:start" to resume.');

        return 0;
    }
}
