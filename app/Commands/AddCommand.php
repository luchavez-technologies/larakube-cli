<?php

namespace App\Commands;

use App\Contracts\HasHiddenComponents;
use App\Contracts\HasLifecycleHooks;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class AddCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'add {items?* : The database(s), feature(s), blueprint, or storage to add}
                            {--dry-run : Show what will be done without making any changes}';

    /**
     * The console command description.
     */
    protected $description = 'Add or swap databases, Laravel features, blueprints, or storage';

    /**
     * Configure the command to ignore validation errors so we can forward arbitrary flags.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addArchitecturalOptions();
    }

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->checkPrerequisites()) {
            return 1;
        }

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $name = Str::slug(basename($projectPath));
        $config = $this->getProjectConfig($projectPath);

        if (! $config->getPath() || $config->getPath() !== $projectPath) {
            $config->setPath($projectPath);
        }

        if (! $config->getName() || $config->getName() !== $name) {
            $config->setName($name);
        }

        $selectedItems = $this->argument('items');

        // 1. Collect items from flags
        foreach (DatabaseDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $selectedItems[] = $case->value;
            }
        }

        foreach (LaravelFeature::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $selectedItems[] = $case->value;
            }
        }

        foreach (CacheDriver::cases() as $case) {
            if ($this->option($case->value)) {
                $selectedItems[] = $case->value;
            }
        }

        foreach (StorageDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $selectedItems[] = $case->value;
            }
        }

        foreach (ScoutDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $selectedItems[] = $case->value;
            }
        }

        foreach (Blueprint::cases() as $case) {
            if ($case === Blueprint::LARAVEL) {
                continue;
            }
            if ($this->option($case->value)) {
                $selectedItems[] = $case->value;
            }
        }

        if (empty($selectedItems)) {
            $this->laraKubeInfo('Welcome to the Architectural Evolution wizard.');

            $type = select(
                label: 'What would you like to evolve?',
                options: [
                    'feature' => 'Laravel Feature (Horizon, Reverb, etc.)',
                    'database' => 'Database Engine (MySQL, Postgres, etc.)',
                    'cache' => 'Cache Driver (Redis, Memcached)',
                    'storage' => 'Object Storage (MinIO, Garage)',
                    'php_version' => 'PHP Version (8.4, 8.5, etc.)',
                    'server' => 'Server Variation (FrankenPHP, Nginx, Apache)',
                    'os' => 'Operating System (Alpine, Debian)',
                    'extension' => 'PHP Extension (gd, bcmath, etc.)',
                    'blueprint' => 'Specialized Blueprint (Filament, etc.)',
                ]
            );

            if ($type === 'extension') {
                $ext = text(
                    label: 'Enter the name of the PHP extension to add:',
                    placeholder: 'imagick',
                    required: true
                );

                $this->call('ext:add', ['extension' => $ext]);

                return 0;
            }

            if ($type === 'php_version') {
                $version = select(
                    label: 'Select your new PHP version:',
                    options: PhpVersion::getSelectOptions($config),
                    default: $config->getPhpVersion()->value
                );

                $this->updatePhpVersion(PhpVersion::from($version), $config);

                return 0;
            }

            if ($type === 'server') {
                $variation = select(
                    label: 'Select your new server variation:',
                    options: ServerVariation::getSelectOptions($config),
                    default: $config->getServerVariation()?->value
                );

                $this->updateServerVariation(ServerVariation::from($variation), $config);

                return 0;
            }

            if ($type === 'os') {
                $os = select(
                    label: 'Select your new base operating system:',
                    options: OperatingSystem::getSelectOptions($config),
                    default: $config->getOs()->value
                );

                $this->updateOs(OperatingSystem::from($os), $config);

                return 0;
            }

            if ($type === 'database') {
                $availableDbs = collect(DatabaseDriver::cases())
                    ->filter(fn ($db) => ! in_array($db, $config->getDatabases()))
                    ->mapWithKeys(fn ($db) => [$db->value => $db->value])
                    ->toArray();

                if (empty($availableDbs)) {
                    $this->laraKubeInfo('All supported databases are already installed.');

                    return 0;
                }

                $selectedItems = multiselect(label: 'Select databases to add:', options: $availableDbs, required: true);
            }

            if ($type === 'cache') {
                $available = collect(CacheDriver::cases())
                    ->filter(fn ($d) => ! in_array($d, $config->getCacheDrivers()))
                    ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                    ->all();

                if (empty($available)) {
                    $this->laraKubeInfo('All supported cache drivers are already installed.');

                    return 0;
                }

                $selectedItems = multiselect(label: 'Select cache drivers to add:', options: $available, required: true);
            }

            if ($type === 'feature') {
                $availableFeatures = collect(LaravelFeature::cases())
                    ->filter(fn ($f) => ! in_array($f, $config->getFeatures()))
                    ->mapWithKeys(fn ($f) => [$f->value => $f->value])
                    ->toArray();

                if (empty($availableFeatures)) {
                    $this->laraKubeInfo('All supported features are already installed.');

                    return 0;
                }

                $selectedItems = multiselect(label: 'Select features to add:', options: $availableFeatures, required: true);
            }

            if ($type === 'storage') {
                $available = collect(StorageDriver::cases())
                    ->filter(fn ($d) => ! in_array($d, $config->getObjectStorages()))
                    ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])
                    ->all();

                if (empty($available)) {
                    $this->laraKubeInfo('All supported storage engines are already installed.');

                    return 0;
                }

                $selectedItems = multiselect(label: 'Select object storage engines to add:', options: $available, required: true);
            }

            if ($type === 'blueprint') {
                $available = collect(Blueprint::cases())
                    ->filter(fn ($b) => $b !== Blueprint::LARAVEL && ! in_array($b, $config->getBlueprints()))
                    ->mapWithKeys(fn ($b) => [$b->value => $b->getLabel()])
                    ->all();

                if (empty($available)) {
                    $this->laraKubeInfo('All supported blueprints are already installed.');

                    return 0;
                }

                $selectedItems = multiselect(label: 'Select specialized blueprints:', options: $available, required: true);
            }
        }

        $addedCount = 0;
        foreach (array_unique($selectedItems) as $item) {
            $matched = false;

            $database = DatabaseDriver::tryFrom($item);
            if ($database) {
                $this->addDatabase($database, $config);
                $addedCount++;

                continue;
            }

            $cache = CacheDriver::tryFrom($item);
            if ($cache) {
                $this->addCacheDriver($cache, $config);
                $addedCount++;

                continue;
            }

            $feature = LaravelFeature::tryFrom($item);
            if ($feature) {
                $this->addFeature($feature, $config);
                $addedCount++;

                continue;
            }

            $scout = ScoutDriver::tryFrom($item);
            if ($scout) {
                $this->addScoutDriver($scout, $config);
                $addedCount++;

                continue;
            }

            $storage = StorageDriver::tryFrom($item);
            if ($storage) {
                $this->addStorage($storage, $config);
                $addedCount++;

                continue;
            }

            $blueprint = Blueprint::tryFrom($item);
            if ($blueprint) {
                $this->addBlueprint($blueprint, $config);
                $addedCount++;

                continue;
            }

            if (! $matched) {
                $this->laraKubeError("Unrecognized item: '{$item}'. Use larakube add (without arguments) for an interactive list.");
            }
        }

        if ($addedCount > 0) {
            $this->laraKubeInfo('Architectural updates complete. Please run "larakube up" to sync your cluster.');

            // Collect instructions from all added components
            $allInstructions = [];
            foreach (array_unique($selectedItems) as $item) {
                $component = DatabaseDriver::tryFrom($item)
                    ?? CacheDriver::tryFrom($item)
                    ?? LaravelFeature::tryFrom($item)
                    ?? ScoutDriver::tryFrom($item)
                    ?? StorageDriver::tryFrom($item)
                    ?? Blueprint::tryFrom($item);

                if ($component instanceof HasArtisanCommands && ! $config->isScaffolding) {
                    foreach ($component->getArtisanCommands($config) as $cmd) {
                        $allInstructions[] = "Run: <fg=blue>larakube art $cmd</>";
                    }
                }

                if ($component instanceof HasLifecycleHooks) {
                    $allInstructions = array_merge($allInstructions, $component->getPostInstallInstructions($config));
                }
            }

            if (! empty($allInstructions)) {
                $this->newLine();
                $this->warn('Perform these one-time architectural steps:');
                foreach ($allInstructions as $line) {
                    $this->line("  $line");
                }
            }
        }

        return 0;

    }

    protected function addDatabase(DatabaseDriver $engine, ConfigData $config): void
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
        if (! $this->option('no-interaction') && ! confirm("Apply changes for '{$engine->value}'?", true)) {
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

    protected function addCacheDriver(CacheDriver $driver, ConfigData $config): void
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
        if (! $this->option('no-interaction') && ! confirm("Apply changes for '{$driver->value}'?", true)) {
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

    protected function addStorage(StorageDriver $storage, ConfigData $config): void
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
        if (! $this->option('no-interaction') && ! confirm("Apply changes for '{$storage->value}'?", true)) {
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

    protected function addScoutDriver(ScoutDriver $scout, ConfigData $config): void
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
        if (! $this->option('no-interaction') && ! confirm("Apply changes for '{$scout->value}'?", true)) {
            return;
        }

        if ($isMain) {
            $config->setScoutDriver($scout);
            $this->syncEnvFile($projectPath, $scout->getEnvironmentVariables($config));
        } else {
            $config->addScoutDriver($scout);
        }

        $this->saveProjectConfig($projectPath, $config);
        $this->addFeature(LaravelFeature::SCOUT, $config); // This handles the K8s manifests
    }

    protected function addBlueprint(Blueprint $blueprint, ConfigData $config): void
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
        if (! $this->option('no-interaction') && ! confirm("Apply blueprint '{$blueprint->value}'?", true)) {
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

    protected function addFeature(LaravelFeature $feature, ConfigData $config): void
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
        if (! $this->option('no-interaction') && ! confirm("Apply feature '{$feature->value}'?", true)) {
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

    protected function updatePhpVersion(PhpVersion $version, ConfigData $config): void
    {
        $projectPath = $config->getPath();
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
        $projectPath = $config->getPath();
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
        $projectPath = $config->getPath();
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

    protected function displayInstructions(array $instructions): void
    {
        if (empty($instructions)) {
            return;
        }
        $this->newLine();
        $this->warn('Next Steps:');
        foreach ($instructions as $line) {
            $this->line("  $line");
        }
        $this->newLine();
    }
}
