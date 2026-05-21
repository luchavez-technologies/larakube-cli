<?php

namespace App\Traits;

use App\Data\ConfigData;
use Exception;

trait InteractsWithProjectConfig
{
    /**
     * Ensure the current directory is a LaraKube project.
     */
    protected function isLaraKubeProject(bool $showError = true): bool
    {
        if (file_exists(getcwd().'/'.ConfigData::CONFIG_FILE)) {
            return true;
        }

        if ($showError) {
            $this->renderHeader();
            $this->laraKubeError('Not a LaraKube project.');

            if (file_exists(getcwd().'/artisan')) {
                $this->info('  💡 TIP: This looks like a valid Laravel project!');
                $this->info('  Run <fg=yellow;options=bold>larakube init</> to orchestrate it for Kubernetes.');
            }
        }

        return false;
    }

    /**
     * Get the project configuration from .larakube.json.
     */
    protected function getProjectConfig(?string $projectPath = null): ?ConfigData
    {
        try {
            return ConfigData::loadFromFile($projectPath ?: getcwd());
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Get the project configuration object.
     */
    protected function getProjectConfigObject(string $projectPath): ConfigData
    {
        try {
            return ConfigData::loadFromFile($projectPath);
        } catch (Exception) {
            return new ConfigData;
        }
    }

    /**
     * Update a specific key in the project configuration.
     */
    protected function updateProjectConfig(string $projectPath, string $key, mixed $value): void
    {
        $config = $this->getProjectConfigObject($projectPath);
        $data = $config->toArray();

        if (is_array($value) && isset($data[$key]) && is_array($data[$key])) {
            $data[$key] = array_unique(array_merge($data[$key], $value));
        } else {
            $data[$key] = $value;
        }

        ConfigData::from($data)->saveToFile($projectPath);
    }

    /**
     * Save the full configuration object, registering it in the local database
     * and optionally backing it up to the Kubernetes cluster.
     */
    protected function saveProjectConfig(string $projectPath, ConfigData $config, ?string $environment = null): void
    {
        // 1. Save to local file (.larakube.json)
        $config->saveToFile($projectPath);

        // 2. Register in internal SQLite database (Speed & List commands)
        if (method_exists($this, 'registerProject')) {
            $this->registerProject($projectPath, $config);
        }

        // 3. Optional: Backup to Cluster (Disilience/Disaster Recovery)
        if ($environment) {
            $appName = $config->getName() ?? basename($projectPath);
            $namespace = method_exists($this, 'getNamespace') ? $this->getNamespace($environment, $appName) : "{$appName}-{$environment}";
            $config->backupToCluster($namespace);
        }
    }
}
