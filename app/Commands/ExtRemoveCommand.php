<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class ExtRemoveCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ext:remove {extension? : The name of the PHP extension to remove}';

    /**
     * The console command description.
     */
    protected $description = 'Remove a PHP extension from your project';

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

        $extension = $this->argument('extension');
        if (! $extension) {
            $extensions = $config->getAdditionalExtensions();
            if (empty($extensions)) {
                $this->laraKubeInfo('No custom PHP extensions are currently added to this project.');

                return 0;
            }

            $extension = select(
                label: 'Which PHP extension would you like to remove?',
                options: array_combine($extensions, $extensions),
                default: reset($extensions),
            );
        }

        $extension = strtolower($extension);
        $dockerfilePath = $projectPath.'/Dockerfile.php';

        // 1. Update config
        if (! in_array($extension, $config->getAdditionalExtensions())) {
            $this->laraKubeInfo("Extension '{$extension}' is not in your configuration. Skipping...");
        } else {
            $config->removeAdditionalExtension($extension);
            $this->saveProjectConfig($projectPath, $config);
            $this->laraKubeInfo("Removed '{$extension}' from configuration.");
        }

        // 2. Update Dockerfile.php
        if (file_exists($dockerfilePath)) {
            $dockerfile = file_get_contents($dockerfilePath);

            if (preg_match('/RUN install-php-extensions (.*)/', $dockerfile, $matches)) {
                $currentExts = array_filter(explode(' ', trim($matches[1])));
                if (in_array($extension, $currentExts)) {
                    $newExts = implode(' ', array_values(array_diff($currentExts, [$extension])));
                    $dockerfile = str_replace($matches[0], "RUN install-php-extensions {$newExts}", $dockerfile);
                    file_put_contents($dockerfilePath, $dockerfile);
                    $this->laraKubeInfo("Updated Dockerfile.php (removed '{$extension}')");
                }
            }
        }

        $this->line('');
        info("Success! Run 'larakube up' to rebuild your image without the extension.");

        return 0;
    }
}
