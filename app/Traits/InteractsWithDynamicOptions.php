<?php

namespace App\Traits;

use App\Contracts\HasHiddenComponents;
use App\Data\ConfigData;
use App\Enums\AiProvider;
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
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

trait InteractsWithDynamicOptions
{
    /**
     * Define the dynamic architectural options for the command.
     */
    protected function addArchitecturalOptions(): void
    {
        $options = [
            ...Blueprint::getCommandOptionArrays(),
            ...ServerVariation::getCommandOptionArrays(),
            ...OperatingSystem::getCommandOptionArrays(),
            ...PhpVersion::getCommandOptionArrays(),
            ...DatabaseDriver::getCommandOptionArrays(),
            ...CacheDriver::getCommandOptionArrays(),
            ...LaravelFeature::getCommandOptionArrays(),
            ...FrontendStack::getCommandOptionArrays(),
            ...PackageManager::getCommandOptionArrays(),
            ...StorageDriver::getCommandOptionArrays(),
            ...ScoutDriver::getCommandOptionArrays(),
            ...DeploymentStrategy::getCommandOptionArrays(),
        ];

        foreach ($options as $option) {
            try {
                $this->addOption(
                    name: Arr::get($option, 'name'),
                    mode: InputOption::VALUE_NONE,
                    description: Arr::get($option, 'description'),
                );
            } catch (InvalidArgumentException $e) {
                // Option already exists, skip it
            }
        }
    }

    /**
     * Define the dynamic AI provider options for the command.
     */
    protected function addAiProviderOptions(int $mode = InputOption::VALUE_NONE): void
    {
        foreach (AiProvider::getCommandOptionArrays() as $option) {
            $this->addOption(
                name: Arr::get($option, 'name'),
                mode: $mode,
                description: Arr::get($option, 'description'),
            );
        }
    }

    /**
     * Build the configuration array from CLI flags.
     */
    protected function buildConfigFromFlags(?ConfigData $base = null): ConfigData
    {
        $config = $base ?? new ConfigData;

        // Blueprints
        foreach (Blueprint::cases() as $case) {
            if ($this->option($case->value)) {
                $config->addBlueprint($case);
            }
        }

        // Laravel Features. Additive, not a blanket replace — a $base config may
        // already carry features from disk (re-init), and CLI flags here only
        // ever mean "also enable this one", never "reset to just these".
        foreach (LaravelFeature::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value) && ! $config->hasFeature($case)) {
                $config->addFeature($case);
            }
        }

        if ($config->hasFeature(LaravelFeature::HORIZON)) {
            $config->setCacheDriver(CacheDriver::REDIS);
        }

        // Server Variations
        foreach (ServerVariation::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setServerVariation($case);

                if ($case === ServerVariation::FRANKENPHP) {
                    $config->addFeature(LaravelFeature::OCTANE);
                }
                break;
            }
        }

        // Operating Systems
        foreach (OperatingSystem::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setOs($case);
                break;
            }
        }

        // PHP Versions
        foreach (PhpVersion::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setPhpVersion($case);
                break;
            }
        }

        // Database Drivers
        foreach (DatabaseDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->addDatabase($case);
            }
        }

        // Cache Drivers
        foreach (CacheDriver::cases() as $case) {
            if ($this->option($case->value)) {
                $config->setCacheDriver($case);
                break;
            }
        }

        // Storage Drivers
        foreach (StorageDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setObjectStorage($case);
                break;
            }
        }

        // Scout Drivers
        foreach (ScoutDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setScoutDriver($case);
                break;
            }
        }

        // Frontend Stacks
        foreach (FrontendStack::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setFrontend($case);
                break;
            }
        }

        // Package Managers
        foreach (PackageManager::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setPackageManager($case);
                break;
            }
        }

        // Deployment Strategy
        foreach (DeploymentStrategy::cases() as $case) {
            if ($this->option($case->value)) {
                $config->setStrategy($case);
                break;
            }
        }

        // Default to fast-mode compatible values if not provided
        if ($this->option('fast')) {
            if (! $config->hasBlueprints()) {
                $config->addBlueprint(Blueprint::LARAVEL);
            }

            if (! $config->hasServerVariation()) {
                $config->setServerVariation(ServerVariation::FRANKENPHP);
            }

            if (! $config->hasPhpVersion()) {
                $config->setPhpVersion(PhpVersion::PHP_8_5);
            }

            if (! $config->hasOs()) {
                $config->setOs(OperatingSystem::ALPINE);
            }

            if (! $config->hasPackageManager()) {
                $config->setPackageManager(PackageManager::NPM);
            }

            if (empty($config->getDatabases())) {
                $config->addDatabase(DatabaseDriver::MYSQL);
            }

            if (! $config->hasCacheDriver()) {
                $config->setCacheDriver(CacheDriver::REDIS);
            }

            if (! $config->hasStrategy()) {
                $config->setStrategy(DeploymentStrategy::SINGLE_NODE);
            }

            if (! $config->hasGithubActions()) {
                $config->setGithubActions(true);
            }
        }

        $config->resolveDependencies();

        return $config;
    }
}
