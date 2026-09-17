<?php

namespace App\Commands\Cloud;

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCloudProxy;
use LaravelZero\Framework\Commands\Command;

/**
 * Take an environment's hosts off the Cloudflare proxy (back to DNS-only).
 */
class CloudUnproxyCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput, ManagesCloudProxy;

    protected $signature = 'cloud:unproxy
        {environment : The cloud environment whose hosts go back to DNS-only}';

    protected $description = 'Take an environment\'s hosts off the Cloudflare proxy (DNS-only)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = (string) getcwd();
        $config = $this->getProjectConfig($projectPath);

        if ($config === null) {
            $this->laraKubeError('Run this inside a LaraKube CLI project.');

            return 1;
        }

        if ($env === 'local' || $config->getEnvironment($env) === null) {
            $this->laraKubeError("'{$env}' isn't a cloud environment of this project.");

            return 1;
        }

        if (! $config->isProxied($env)) {
            $this->laraKubeInfo("'{$env}' is already DNS-only.");

            return 0;
        }

        $this->applyProxySetting($config, $env, $projectPath, false);

        return 0;
    }
}
