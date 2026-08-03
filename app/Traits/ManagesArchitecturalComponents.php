<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * The side-effecting "add/remove/update a single architectural component"
 * logic shared by `larakube add`, `larakube remove`, and a re-run of
 * `larakube init` (which replays a wizard diff through these same methods).
 */
trait ManagesArchitecturalComponents
{
    use GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput;

    protected function addDatabase(DatabaseDriver $engine, ConfigData $config, bool $skipConfirm = false): void
    {
        $projectPath = $config->getPath();
        $existingDbs = $config->getDatabases();

        if (in_array($engine, $existingDbs)) {
            $this->laraKubeInfo("Database '{$engine->value}' is already added. Skipping...");

            return;
        }

        // FrankenPHP + SQLite Guard
        if ($engine === DatabaseDriver::SQLITE && $config->getServerVariation() === ServerVariation::FRANKENPHP) {
            $this->laraKubeError('Architectural Incompatibility: SQLite + FrankenPHP.');

            return;
        }

        $primaryDb = $config->getDatabase();
        $isMain = is_null($primaryDb);
        $migrateFirst = false;

        if ($primaryDb && $engine->value !== $primaryDb->value) {
            $this->warn(" ⚠ A primary database ({$primaryDb->value}) is already configured.");
            $isMain = confirm("Would you like to swap '{$engine->value}' as your NEW primary database?", true);
            if ($isMain) {
                $migrateFirst = confirm("Do you need to migrate data from '{$primaryDb->value}' to '{$engine->value}' first?", true);
            }
        }

        $this->laraKubeInfo("Previewing Addition: Database '{$engine->value}'");
        if ($this->option('dry-run')) {
            return;
        }
        if (! $skipConfirm && ! $this->option('no-interaction') && ! confirm("Apply changes for '{$engine->value}'?", true)) {
            return;
        }

        $this->withSpin("Adding database '$engine->value'...", function () use ($engine, $config) {
            $engine->updateK8s($config);
            if ($config->id) {
                $this->logToConsole($config->id, 'add', "Added database '{$engine->value}'");
            }
        });

        if ($isMain) {
            $config->setDatabase($engine);
            if ($migrateFirst) {
                $this->syncEnvFile($projectPath, $engine->getEnvironmentVariables($config), true);
            } else {
                $this->syncEnvFile($projectPath, $engine->getEnvironmentVariables($config));
            }
        } else {
            $config->addDatabase($engine);
        }

        $this->saveProjectConfig($projectPath, $config);
        $this->installComponent($config, $engine);
    }

    protected function addCacheDriver(CacheDriver $driver, ConfigData $config, bool $skipConfirm = false): void
    {
        $projectPath = $config->getPath();
        if (in_array($driver, $config->getCacheDrivers())) {
            $this->laraKubeInfo("Cache driver '{$driver->value}' is already added. Skipping...");

            return;
        }

        $primary = $config->getCacheDriver();
        $isMain = is_null($primary);
        if ($primary && $driver->value !== $primary->value) {
            $this->warn(" ⚠ A primary cache driver ({$primary->value}) is already configured.");
            $isMain = confirm("Would you like to swap '{$driver->value}' as your NEW primary cache driver?", true);
        }

        $this->laraKubeInfo("Previewing Addition: Cache Driver '{$driver->value}'");
        if ($this->option('dry-run')) {
            return;
        }
        if (! $skipConfirm && ! $this->option('no-interaction') && ! confirm("Apply changes for '{$driver->value}'?", true)) {
            return;
        }

        $this->withSpin("Adding cache driver '{$driver->value}'...", function () use ($driver, $config) {
            $driver->updateK8s($config);
            if ($config->id) {
                $this->logToConsole($config->id, 'add', "Added cache driver '{$driver->value}'");
            }
        });

        if ($isMain) {
            $config->setCacheDriver($driver);
            $this->syncEnvFile($projectPath, $driver->getEnvironmentVariables($config));
        } else {
            $config->addCacheDriver($driver);
        }

        $this->saveProjectConfig($projectPath, $config);
        $this->installComponent($config, $driver);
    }

    protected function addStorage(StorageDriver $storage, ConfigData $config, bool $skipConfirm = false): void
    {
        $projectPath = $config->getPath();
        if (in_array($storage, $config->getObjectStorages())) {
            $this->laraKubeInfo("Storage '{$storage->value}' is already added. Skipping...");

            return;
        }

        $primary = $config->getObjectStorage();
        $isMain = is_null($primary);
        if ($primary && $storage->value !== $primary->value) {
            $this->warn(" ⚠ A primary storage engine ({$primary->value}) is already configured.");
            $isMain = confirm("Would you like to swap '{$storage->value}' as your NEW primary storage?", true);
        }

        $this->laraKubeInfo("Previewing Addition: Storage '{$storage->value}'");
        if ($this->option('dry-run')) {
            return;
        }
        if (! $skipConfirm && ! $this->option('no-interaction') && ! confirm("Apply changes for '{$storage->value}'?", true)) {
            return;
        }

        $this->withSpin("Adding storage '{$storage->value}'...", function () use ($storage, $config) {
            $storage->updateK8s($config);
            if ($config->id) {
                $this->logToConsole($config->id, 'add', "Added storage '{$storage->value}'");
            }
        });

        if ($isMain) {
            $config->setObjectStorage($storage);
            $this->syncEnvFile($projectPath, $storage->getEnvironmentVariables($config));
        } else {
            $config->addObjectStorage($storage);
        }

        $this->saveProjectConfig($projectPath, $config);
        $this->installComponent($config, $storage);
    }

    protected function addScoutDriver(SearchDriver $scout, ConfigData $config, bool $skipConfirm = false): void
    {
        $projectPath = $config->getPath();
        if (in_array($scout, $config->getScoutDrivers())) {
            $this->laraKubeInfo("Scout driver '{$scout->value}' is already added. Skipping...");

            return;
        }

        $primary = $config->getScoutDriver();
        $isMain = is_null($primary);
        if ($primary && $scout->value !== $primary->value) {
            $this->warn(" ⚠ A primary search driver ({$primary->value}) is already configured.");
            $isMain = confirm("Would you like to swap '{$scout->value}' as your NEW primary search driver?", true);
        }

        $this->laraKubeInfo("Previewing Addition: Scout Driver '{$scout->value}'");
        if ($this->option('dry-run')) {
            return;
        }
        if (! $skipConfirm && ! $this->option('no-interaction') && ! confirm("Apply changes for '{$scout->value}'?", true)) {
            return;
        }

        if ($isMain) {
            $config->setScoutDriver($scout);
            $this->syncEnvFile($projectPath, $scout->getEnvironmentVariables($config));
        } else {
            $config->addScoutDriver($scout);
        }

        $this->saveProjectConfig($projectPath, $config);
        $this->addFeature(LaravelFeature::SCOUT, $config, $skipConfirm); // This handles the K8s manifests
    }

    protected function addBlueprint(Blueprint $blueprint, ConfigData $config, bool $skipConfirm = false): void
    {
        $projectPath = $config->getPath();
        if (in_array($blueprint, $config->getBlueprints())) {
            $this->laraKubeInfo("Blueprint '{$blueprint->value}' is already added. Skipping...");

            return;
        }

        $this->laraKubeInfo("Previewing Addition: Blueprint '{$blueprint->value}'");
        if ($this->option('dry-run')) {
            return;
        }
        if (! $skipConfirm && ! $this->option('no-interaction') && ! confirm("Apply blueprint '{$blueprint->value}'?", true)) {
            return;
        }

        $config->addBlueprint($blueprint);
        $this->saveProjectConfig($projectPath, $config);
        $this->orchestrateProjectScaffolding($config, false, false);
        $this->generateDockerfiles($config);
        $this->buildImage($config);
        $this->installComponent($config, $blueprint);

        if ($config->id) {
            $this->logToConsole($config->id, 'add', "Applied blueprint '{$blueprint->value}'");
        }
    }

    protected function addFeature(LaravelFeature $feature, ConfigData $config, bool $skipConfirm = false): void
    {
        $projectPath = $config->getPath();
        if (in_array($feature, $config->getFeatures())) {
            $this->laraKubeInfo("Feature '{$feature->value}' is already added. Skipping...");

            return;
        }

        $this->laraKubeInfo("Previewing Addition: Feature '{$feature->value}'");
        if ($this->option('dry-run')) {
            return;
        }
        if (! $skipConfirm && ! $this->option('no-interaction') && ! confirm("Apply feature '{$feature->value}'?", true)) {
            return;
        }

        $this->withSpin("Adding feature '{$feature->value}'...", function () use ($feature, $config) {
            $feature->updateK8s($config);
            if ($config->id) {
                $this->logToConsole($config->id, 'add', "Added feature '{$feature->value}'");
            }
        });

        $config->addFeature($feature);
        $this->saveProjectConfig($projectPath, $config);
        $this->installComponent($config, $feature);
    }

    protected function updateCloudConfig(ConfigData $config): void
    {
        $cloudEnvs = $config->getCloudEnvironments();
        if (empty($cloudEnvs)) {
            $this->laraKubeWarn('No cloud environment configured yet. Run `larakube env` first (e.g. `larakube env production`).');

            return;
        }

        $environment = count($cloudEnvs) === 1 ? $cloudEnvs[0] : select(
            label: 'Which environment would you like to update?',
            options: $cloudEnvs,
        );

        $this->laraKubeInfo("Updating cloud configuration for '{$environment}'...");

        // Same ingress/managed-services wizard `larakube env` and `cloud:configure`
        // use, pre-filled with this env's current values.
        $config->environments[$environment]->ingress = $this->gatherEnvironmentIngress($config, $environment);

        if (! empty($config->getManageableServices())) {
            $config->environments[$environment]->managed = $this->gatherEnvironmentManaged($config, $environment);
        }

        $this->finishArchitecturalPivot($config);
    }

    protected function updatePhpVersion(PhpVersion $version, ConfigData $config): void
    {
        if ($config->getPhpVersion() === $version) {
            $this->laraKubeInfo("PHP Version is already '{$version->value}'. Skipping...");

            return;
        }

        $this->laraKubeInfo("Pivoting PHP Version to: {$version->getLabel()}");

        $config->setPhpVersion($version);
        $this->finishArchitecturalPivot($config);
    }

    protected function updateServerVariation(ServerVariation $variation, ConfigData $config): void
    {
        if ($config->getServerVariation() === $variation) {
            $this->laraKubeInfo("Server Variation is already '{$variation->value}'. Skipping...");

            return;
        }

        $this->laraKubeInfo("Pivoting Server Variation to: {$variation->getLabel()}");

        $config->setServerVariation($variation);
        $this->finishArchitecturalPivot($config);
    }

    protected function updateOs(OperatingSystem $os, ConfigData $config): void
    {
        if ($config->getOs() === $os) {
            $this->laraKubeInfo("Operating System is already '{$os->value}'. Skipping...");

            return;
        }

        $this->laraKubeInfo("Pivoting Base OS to: {$os->getLabel()}");

        $config->setOs($os);
        $this->finishArchitecturalPivot($config);
    }

    protected function finishArchitecturalPivot(ConfigData $config): void
    {
        $projectPath = $config->getPath();

        $this->withSpin('Updating project DNA and manifests...', function () use ($config, $projectPath) {
            $this->saveProjectConfig($projectPath, $config);
            $this->orchestrateProjectScaffolding($config, false, false);
            $this->generateDockerfiles($config);
        });

        if (confirm('Architectural pivot requires an image rebuild. Would you like to build now?', true)) {
            $this->buildImage($config);
        }

        $this->laraKubeInfo('Evolution complete! Run "larakube up" to deploy the new architecture.');
    }

    protected function removeDatabase(DatabaseDriver $engine, ConfigData $config): void
    {
        $config->removeDatabase($engine);

        // If we just removed the primary, promote the first secondary or fallback to SQLite
        if (is_null($config->getDatabase())) {
            $next = collect($config->getDatabases())->first();

            if ($next) {
                $this->laraKubeInfo("Promoting '{$next->value}' to primary database.");
                $config->setDatabase($next);
            } else {
                $this->warn(' ⚠ No secondary databases found. Falling back to SQLite to ensure application stability.');
                $config->setDatabase(DatabaseDriver::SQLITE);
                $next = DatabaseDriver::SQLITE;
            }

            $this->syncEnvFile($config->getPath(), $next->getEnvironmentVariables($config));
        }
    }

    protected function removeCache(CacheDriver $driver, ConfigData $config): void
    {
        $config->removeCacheDriver($driver);

        // Promote next primary if needed, or fallback to 'database' driver
        if (is_null($config->getCacheDriver())) {
            $next = collect($config->getCacheDrivers())->first();

            if ($next) {
                $this->laraKubeInfo("Promoting '{$next->value}' to primary cache driver.");
            } else {
                $this->warn(" ⚠ No cache drivers left. Falling back to 'database' driver.");
                $next = CacheDriver::DATABASE;
            }

            $config->setCacheDriver($next);
            $this->syncEnvFile($config->getPath(), $next->getEnvironmentVariables($config));
        }
    }

    protected function removeStorage(StorageDriver $storage, ConfigData $config): void
    {
        $config->removeObjectStorage($storage);

        if (is_null($config->getObjectStorage())) {
            $next = collect($config->getObjectStorages())->first();
            if ($next) {
                $this->laraKubeInfo("Promoting '{$next->value}' to primary storage.");
                $config->setObjectStorage($next);
                $this->syncEnvFile($config->getPath(), $next->getEnvironmentVariables($config));
            }
        }
    }

    protected function removeFeature(LaravelFeature $feature, ConfigData $config, bool $skipConfirm = false): void
    {
        if ($feature === LaravelFeature::SCOUT && $config->getScoutDriver()) {
            $this->warn(" ⚠ Removing Scout will also disable your search driver ({$config->getScoutDriver()->value}).");
            if (! $skipConfirm && ! confirm('Proceed with disabling search?', true)) {
                return;
            }
            $config->setScoutDriver(null);
            $config->setScoutDrivers([]);
        }

        $config->removeFeature($feature);
    }

    protected function removeBlueprint(Blueprint $blueprint, ConfigData $config): void
    {
        $config->removeBlueprint($blueprint);
    }
}
