<?php

namespace App\Commands;

use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ProjectBuildCommand extends Command
{
    use InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'build';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild the local Docker image for the project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        $this->laraKubeInfo("Building local image for '{$config->getName()}'...");

        $this->buildImage($config);

        $this->laraKubeInfo('Image build and cluster import complete! 🚀');
        $this->info('You can now run "larakube up" to use the new image.');

        return 0;
    }
}
