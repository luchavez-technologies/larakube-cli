<?php

namespace App\Commands;

use App\Contracts\HasArtisanCommands;
use App\Contracts\HasHiddenComponents;
use App\Contracts\HasLifecycleHooks;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\CheckPrerequisites;
use App\Traits\GathersEnvironmentData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesArchitecturalComponents;
use Illuminate\Support\Str;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class AddCommand extends Command
{
    use CheckPrerequisites, GathersEnvironmentData, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput, ManagesArchitecturalComponents;

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
                    'cloud' => 'Cloud Configuration (Ingress, Managed Services)',
                ],
            );

            if ($type === 'cloud') {
                $this->updateCloudConfig($config);

                return 0;
            }

            if ($type === 'extension') {
                $ext = text(
                    label: 'Enter the name of the PHP extension to add:',
                    placeholder: 'imagick',
                    required: true,
                );

                $this->call('ext:add', ['extension' => $ext]);

                return 0;
            }

            if ($type === 'php_version') {
                $version = select(
                    label: 'Select your new PHP version:',
                    options: PhpVersion::getSelectOptions($config),
                    default: $config->getPhpVersion()->value,
                );

                $this->updatePhpVersion(PhpVersion::from($version), $config);

                return 0;
            }

            if ($type === 'server') {
                $variation = select(
                    label: 'Select your new server variation:',
                    options: ServerVariation::getSelectOptions($config),
                    default: $config->getServerVariation()?->value,
                );

                $this->updateServerVariation(ServerVariation::from($variation), $config);

                return 0;
            }

            if ($type === 'os') {
                $os = select(
                    label: 'Select your new base operating system:',
                    options: OperatingSystem::getSelectOptions($config),
                    default: $config->getOs()->value,
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

    /**
     * Configure the command to ignore validation errors so we can forward arbitrary flags.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addArchitecturalOptions();
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
