<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use Illuminate\Support\Arr;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait GathersInfrastructureConfig
{
    use InteractsWithGlobalConfig;

    /**
     * Attempt to automatically detect the frontend stack by scanning manifest files.
     */
    protected function detectFrontendStack(ConfigData $config): ?FrontendStack
    {
        $path = $config->getPath() ?? getcwd();

        // 1. Check composer.json for Livewire
        if (file_exists("$path/composer.json")) {
            $composer = file_get_contents("$path/composer.json");
            if (str_contains($composer, 'livewire/livewire')) {
                return FrontendStack::LIVEWIRE;
            }
        }

        // 2. Check package.json for Inertia stacks
        if (file_exists("$path/package.json")) {
            $package = file_get_contents("$path/package.json");

            if (str_contains($package, '@inertiajs/react')) {
                return FrontendStack::REACT;
            }
            if (str_contains($package, '@inertiajs/vue')) {
                return FrontendStack::VUE;
            }
            if (str_contains($package, '@inertiajs/svelte')) {
                return FrontendStack::SVELTE;
            }
        }

        // 3. Check vite.config for plugin markers (Deep scan)
        $viteFile = file_exists("$path/vite.config.ts") ? "$path/vite.config.ts" : "$path/vite.config.js";
        if (file_exists($viteFile)) {
            $vite = file_get_contents($viteFile);

            return match (true) {
                str_contains($vite, '@vitejs/plugin-react') || str_contains($vite, 'react()') => FrontendStack::REACT,
                str_contains($vite, '@vitejs/plugin-vue') || str_contains($vite, 'vue()') => FrontendStack::VUE,
                str_contains($vite, 'svelte()') => FrontendStack::SVELTE,
                default => null,
            };
        }

        return null;
    }

    /**
     * Gather all configuration needed for infrastructure generation.
     */
    protected function gatherConfig(ConfigData $config): ConfigData
    {
        // 1. Specialized Blueprints
        $availableBlueprints = collect(Blueprint::cases())
            ->filter(fn ($b) => $b !== Blueprint::LARAVEL && ! $b->isHidden($config))
            ->mapWithKeys(fn ($b) => [$b->value => $b->getLabel()])
            ->all();

        if (! empty($availableBlueprints)) {
            $blueprints = multiselect(
                label: 'Choose specialized blueprints (Optional):',
                options: $availableBlueprints,
                default: array_map(fn ($b) => $b->value, $config->getBlueprints())
            );

            $config->setBlueprints($blueprints);
        }

        // 2. Server Variation
        $variation = select(
            label: 'What server variation would you like to use?',
            options: ServerVariation::getSelectOptions($config),
            default: $config->getServerVariation()?->value ?? ServerVariation::FPM_NGINX->value
        );

        $config->setServerVariation(ServerVariation::from($variation));

        // Post-server logic (Octane check)
        if ($config->getServerVariation() === ServerVariation::FRANKENPHP && ! $config->hasFeature(LaravelFeature::OCTANE)) {
            $config->addFeature(LaravelFeature::OCTANE);
        }

        // 3. Laravel Features
        $options = LaravelFeature::getSelectOptions($config);
        if (! empty($options)) {
            $features = multiselect(
                label: 'Select Laravel features:',
                options: $options,
                default: array_map(fn ($f) => $f->value, $config->getFeatures()),
                scroll: count($options),
                validate: function (array $values) {
                    if (in_array(LaravelFeature::HORIZON->value, $values) && in_array(LaravelFeature::QUEUES->value, $values)) {
                        return 'You cannot select both Horizon and Queues. Please choose one.';
                    }

                    return null;
                }
            );

            $config->addFeature(...Arr::map($features, fn (string $feature) => LaravelFeature::from($feature)));
        }

        // 4. Frontend Stack
        if (! $config->getFrontend()) {
            $detected = $this->detectFrontendStack($config);

            if ($detected && ! $config->isScaffolding()) {
                $config->setFrontend($detected);
                $this->laraKubeInfo("Detected frontend stack: <fg=cyan;options=bold>{$detected->getLabel()}</>");
            } else {
                $frontend = select(
                    label: 'Which frontend stack are you using?',
                    options: array_merge([null => 'None (API or Custom)'], FrontendStack::getSelectOptions($config)),
                    default: $detected?->value
                );

                if ($frontend) {
                    $config->setFrontend(FrontendStack::from($frontend));
                }
            }
        }

        // 5. PHP & OS Baseline
        if (! $config->hasPhpVersion()) {
            $version = select(
                label: 'What PHP version would you like to use?',
                options: PhpVersion::getSelectOptions($config),
                default: PhpVersion::PHP_8_5->value
            );

            $config->setPhpVersion(PhpVersion::from($version));
        }

        if (! $config->hasOs()) {
            $os = select(
                label: 'What operating system would you like to use?',
                options: OperatingSystem::getSelectOptions($config),
                default: OperatingSystem::ALPINE->value
            );

            $config->setOs(OperatingSystem::from($os));
        }

        // 6. Security & Contact
        if (! $config->hasEmail()) {
            $email = $this->getEmail();

            if (! $email) {
                $email = text(
                    label: 'What is your email address? (used for SSL/Let\'sEncrypt)',
                    placeholder: 'admin@larakube.dev.test',
                    required: true,
                    validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address.'
                );
                $this->setEmail($email);
            }

            $config->setEmail($email);
        }

        // 7. Extensions
        if (! $config->hasAdditionalExtensions()) {
            info('Default extensions: ctype, curl, dom, fileinfo, filter, hash, mbstring, mysqli, opcache, openssl, pcntl, pcre, pdo_mysql, pdo_pgsql, redis, session, tokenizer, xml, zip');
            $extensions = text(label: 'Enter additional extensions (comma-separated):', placeholder: 'intl,gd');
            $config->setAdditionalExtensions(array_filter(explode(',', str_replace(' ', '', $extensions))));
        }

        // 8. Scout (If enabled)
        if ($config->hasFeature(LaravelFeature::SCOUT)) {
            $driver = select(
                label: 'Which primary search driver would you like to use for Scout?',
                options: ScoutDriver::getSelectOptions($config),
                default: $config->getScoutDriver()?->value ?? ScoutDriver::MEILISEARCH->value
            );

            $config->setScoutDriver(ScoutDriver::from($driver));
        }

        // 9. Package Manager
        $packageManager = select(
            label: 'Choose your JavaScript package manager:',
            options: PackageManager::getSelectOptions($config),
            default: $config->getPackageManager()?->value ?? PackageManager::NPM->value
        );

        $config->setPackageManager(PackageManager::from($packageManager));

        // 10. Object Storage
        $storage = select(
            label: 'Which primary object storage would you like to use?',
            options: array_merge([null => 'None'], StorageDriver::getSelectOptions($config)),
            default: $config->getObjectStorage()?->value
        );

        if ($driver = StorageDriver::tryFrom($storage)) {
            $config->setObjectStorage($driver);
        }

        // 11. Database
        $defaultDb = $config->getServerVariation() === ServerVariation::FRANKENPHP ? DatabaseDriver::MYSQL->value : DatabaseDriver::SQLITE->value;

        if ($config->hasFeature(LaravelFeature::AI)) {
            $defaultDb = DatabaseDriver::POSTGRESQL->value;
            $this->laraKubeInfo('AI SDK detected: PostgreSQL with <fg=cyan;options=bold>pgvector</> is recommended for vector storage.');
        }

        $database = select(
            label: 'What primary database engine would you like to use?',
            options: DatabaseDriver::getSelectOptions($config),
            default: $config->getDatabase()?->value ?? $defaultDb
        );

        $config->setDatabase(DatabaseDriver::from($database));

        // 12. Cache
        if ($config->hasFeature(LaravelFeature::HORIZON)) {
            $this->laraKubeInfo('Horizon detected: Auto-selecting Redis for caching and queues.');
            $config->setCacheDriver(CacheDriver::REDIS);
        } else {
            $cache = select(
                label: 'Which primary cache driver would you like to use?',
                options: array_merge([null => 'None'], CacheDriver::getSelectOptions($config)),
                default: $config->getCacheDriver()?->value ?? CacheDriver::REDIS->value
            );

            if ($driver = CacheDriver::tryFrom($cache)) {
                $config->setCacheDriver($driver);
            }
        }

        // 13. Deployment Strategy
        if (! $config->hasStrategy()) {
            $strategy = select(
                label: 'What is your primary deployment strategy?',
                options: DeploymentStrategy::getSelectOptions($config),
                default: DeploymentStrategy::SINGLE_NODE->value
            );

            $config->setStrategy(DeploymentStrategy::from($strategy));
        }

        if (! $config->hasGithubActions()) {
            $config->setGithubActions(confirm(label: 'Would you like to use GitHub Actions?'));
        }

        $config->resolveDependencies();

        return $config;
    }
}
