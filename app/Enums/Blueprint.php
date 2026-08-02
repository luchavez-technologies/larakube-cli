<?php

namespace App\Enums;

use App\Contracts\HasArtisanCommands;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHiddenComponents;
use App\Contracts\HasHosts;
use App\Contracts\HasLabel;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\HasSelectOptions;
use App\Contracts\RequiresPhpExtensions;
use App\Data\ConfigData;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum Blueprint: string implements HasArtisanCommands, HasCommandOptions, HasComposerDependencies, HasEnvironmentVariables, HasHiddenComponents, HasHosts, HasLabel, HasLifecycleHooks, HasSelectOptions, RequiresPhpExtensions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;

    public function isHidden(?ConfigData $config = null): bool
    {
        return false;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::LARAVEL => 'Laravel (Standard)',
            self::FILAMENT => 'Filament PHP (Admin Panel)',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} blueprint",
            ];
        }

        return $options;
    }

    /**
     * Get the description of the blueprint.
     */
    public function description(): string
    {
        return match ($this) {
            self::LARAVEL => 'A clean, modern Laravel application.',
            self::FILAMENT => 'The elegant TALL stack admin panel for Laravel.',
        };
    }

    public function getEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return array_merge(
            $this->getPublicEnvironmentVariables($config, $environment),
            $this->getSecretEnvironmentVariables($config, $environment),
        );
    }

    public function getPublicEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return [];
    }

    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return [];
    }

    public function getHosts(ConfigData $config, string $environment = 'local'): array
    {
        return [];
    }

    public function getHostServices(): array
    {
        return [];
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::FILAMENT => [
                'filament/filament',
            ],
            default => [],
        };
    }

    public function getArtisanCommands(?ConfigData $context = null): array
    {
        return match ($this) {
            self::FILAMENT => [
                'filament:install --panels',
            ],
            default => [],
        };
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        // TODO: Implement onPostInstall() method.
    }

    public function getPhpExtensions(): array
    {
        return match ($this) {
            self::FILAMENT => ['intl'],
            default => [],
        };
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        return match ($this) {
            self::FILAMENT => [
                'To create your first admin user, run:',
                'larakube art make:filament-user',
            ],
            default => [],
        };
    }

    case LARAVEL = 'laravel';
    case FILAMENT = 'filament';
}
