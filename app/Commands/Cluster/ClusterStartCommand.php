<?php

namespace App\Commands\Cluster;

use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class ClusterStartCommand extends Command
{
    use InteractsWithOs, LaraKubeOutput, StreamsProcessOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cluster:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resume the local LaraKube cluster';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeInfo('Starting LaraKube cluster...');

        if (Process::run('which k3d')->successful()) {
            $this->runStreaming('k3d cluster start larakube');
        } elseif (Process::run('which k3s')->successful() && $this->isLinux()) {
            $this->info('  Detected native k3s. Using systemctl...');
            passthru('sudo systemctl start k3s');
        } else {
            $this->laraKubeError('No supported cluster engine (k3d or k3s) found.');

            return 1;
        }

        $this->laraKubeInfo('✅ Cluster is online! Run "larakube up" to resume development.');

        return 0;
    }
}
