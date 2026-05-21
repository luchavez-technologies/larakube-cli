<?php

namespace App\Commands;

use App\Contracts\HasHiddenComponents;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class RemoveCommand extends Command
{
    use GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remove {items?* : The database(s), feature(s), blueprint(s), or storage to remove}
                            {--dry-run : Show what will be removed without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Remove architectural components (DB, Features, Storage, etc.)';

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
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $selectedItems = $this->argument('items');

        // 1. Collect items from flags (Skipping hidden components to avoid "option does not exist" errors)
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
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }
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
            $this->laraKubeInfo('Welcome to the De-Architectural Wizard.');

            $type = select(
                label: 'What would you like to remove?',
                options: [
                    'blueprint' => 'Specialized Blueprint',
                    'database' => 'Database Engine',
                    'cache' => 'Cache Driver',
                    'feature' => 'Laravel Feature',
                    'storage' => 'Object Storage',
                    'extension' => 'PHP Extension',
                ]
            );

            if ($type === 'extension') {
                $current = $config->getAdditionalExtensions();
                if (empty($current)) {
                    $this->laraKubeInfo('No additional PHP extensions installed.');

                    return 0;
                }
                $selected = multiselect(label: 'Select extensions to remove:', options: array_combine($current, $current), required: true);

                foreach ($selected as $ext) {
                    $this->call('ext:remove', ['extension' => $ext]);
                }

                return 0;
            }

            if ($type === 'blueprint') {
                $current = array_map(fn ($b) => $b->value, $config->getBlueprints());
                if (empty($current)) {
                    $this->laraKubeInfo('No specialized blueprints installed.');

                    return 0;
                }
                $selectedItems = multiselect(label: 'Select blueprints to remove:', options: array_combine($current, $current), required: true);
            }

            if ($type === 'database') {
                $current = array_map(fn ($d) => $d->value, $config->getDatabases());
                $selectedItems = multiselect(label: 'Select databases to remove:', options: array_combine($current, $current), required: true);
            }

            if ($type === 'cache') {
                $current = array_map(fn ($d) => $d->value, $config->getCacheDrivers());
                $selectedItems = multiselect(label: 'Select cache drivers to remove:', options: array_combine($current, $current), required: true);
            }

            if ($type === 'feature') {
                $current = array_map(fn ($f) => $f->value, $config->getFeatures());
                $selectedItems = multiselect(label: 'Select features to remove:', options: array_combine($current, $current), required: true);
            }

            if ($type === 'storage') {
                $current = array_map(fn ($s) => $s->value, $config->getObjectStorages());
                $selectedItems = multiselect(label: 'Select storage engines to remove:', options: array_combine($current, $current), required: true);
            }
        }

        $items = array_unique($selectedItems);
        $this->laraKubeInfo('Architectural Preview: Service Removal');
        foreach ($items as $item) {
            $this->line("  <fg=red>[REMOVE]</> {$item}");
        }

        if ($this->option('dry-run')) {
            return 0;
        }
        if (! confirm('Apply these architectural removals?', true)) {
            return 0;
        }

        foreach ($items as $item) {
            $database = DatabaseDriver::tryFrom($item);
            if ($database) {
                $this->removeDatabase($database, $config);

                continue;
            }

            $cache = CacheDriver::tryFrom($item);
            if ($cache) {
                $this->removeCache($cache, $config);

                continue;
            }

            $feature = LaravelFeature::tryFrom($item);
            if ($feature) {
                $this->removeFeature($feature, $config);

                continue;
            }

            $storage = StorageDriver::tryFrom($item);
            if ($storage) {
                $this->removeStorage($storage, $config);

                continue;
            }

            $blueprint = Blueprint::tryFrom($item);
            if ($blueprint) {
                $this->removeBlueprint($blueprint, $config);
            }
        }

        $this->withSpin('Updating infrastructure DNA...', function () use ($config) {
            $this->saveProjectConfig($config->getPath(), $config);
            $this->orchestrateProjectScaffolding($config, false, false);
            if ($config->id) {
                $this->logToConsole($config->id, 'remove', 'Architectural components removed');
            }

            return true;
        });

        $this->laraKubeInfo('Removal complete. Please run "larakube up" to sync the cluster.');

        return 0;
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

        // Promote next primary if needed, or fallback to 'database'
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

    protected function removeFeature(LaravelFeature $feature, ConfigData $config): void
    {
        if ($feature === LaravelFeature::SCOUT && $config->getScoutDriver()) {
            $this->warn(" ⚠ Removing Scout will also disable your search driver ({$config->getScoutDriver()->value}).");
            if (! confirm('Proceed with disabling search?', true)) {
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
