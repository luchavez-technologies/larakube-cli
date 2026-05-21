<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class LockCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lock {file : The path to the file you want to protect}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Protect a file from being overwritten by LaraKube orchestration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $file = $this->argument('file');

        if (! file_exists($projectPath.'/'.$file)) {
            $this->laraKubeError("File '{$file}' does not exist in the project root.");

            return 1;
        }

        if ($config->isLocked($file)) {
            $this->laraKubeInfo("File '{$file}' is already locked.");

            return 0;
        }

        $config->addLockedFile($file);
        $this->saveProjectConfig($projectPath, $config);

        info("SUCCESS: '{$file}' is now locked. LaraKube will no longer modify this file.");

        return 0;
    }
}
