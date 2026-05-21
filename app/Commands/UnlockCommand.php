<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class UnlockCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'unlock {file : The path to the file you want to unprotect}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Unprotect a file and allow LaraKube to manage it again';

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

        if (! $config->isLocked($file)) {
            $this->laraKubeInfo("File '{$file}' was not locked.");

            return 0;
        }

        $config->removeLockedFile($file);
        $this->saveProjectConfig($projectPath, $config);

        info("SUCCESS: '{$file}' is now unlocked. LaraKube can now manage this file.");

        return 0;
    }
}
