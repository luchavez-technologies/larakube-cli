<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

use LaravelZero\Framework\Commands\Command;

class ExtAddCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ext:add {extension : The name of the PHP extension to add (e.g. gd, imagick, bcmath)}';

    /**
     * The console command description.
     */
    protected $description = 'Add a PHP extension to your project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $extension = strtolower($this->argument('extension'));
        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $dockerfilePath = $projectPath.'/Dockerfile.php';

        // 1. Update config
        if (in_array($extension, $config->getAdditionalExtensions())) {
            $this->laraKubeInfo("Extension '{$extension}' is already in your configuration.");
        } else {
            $config->addAdditionalExtension($extension);
            $this->saveProjectConfig($projectPath, $config);
            $this->laraKubeInfo("Added '{$extension}' to configuration.");
        }

        // 2. Update Dockerfile.php
        if (file_exists($dockerfilePath)) {
            $dockerfile = file_get_contents($dockerfilePath);

            if (preg_match('/RUN install-php-extensions (.*)/', $dockerfile, $matches)) {
                $currentExts = array_filter(explode(' ', trim($matches[1])));
                if (! in_array($extension, $currentExts)) {
                    $currentExts[] = $extension;
                    $newExts = implode(' ', array_unique($currentExts));
                    $dockerfile = str_replace($matches[0], "RUN install-php-extensions {$newExts}", $dockerfile);
                    file_put_contents($dockerfilePath, $dockerfile);
                    $this->laraKubeInfo("Updated Dockerfile.php with '{$extension}'");
                }
            } elseif (preg_match('/^FROM\s+.*?\s+AS\s+base[^\n]*\n/mi', $dockerfile, $baseMatches)) {
                $block = "USER root\nRUN install-php-extensions {$extension}\nUSER www-data\n\n";
                $dockerfile = str_replace($baseMatches[0], $baseMatches[0]."\n".$block, $dockerfile);
                file_put_contents($dockerfilePath, $dockerfile);
                $this->laraKubeInfo("Added 'RUN install-php-extensions {$extension}' to Dockerfile.php");
            } else {
                warning("Could not find an active 'RUN install-php-extensions' line or base stage in Dockerfile.php. Please add it manually.");
            }
        }

        $this->line('');
        info("Success! Run 'larakube up' to rebuild your image with the new extension.");

        return 0;
    }
}
