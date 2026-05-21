<?php

namespace App\Enums;

use App\Contracts\AsDependency;
use App\Contracts\HasArtisanCommands;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasDependencies;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHosts;
use App\Contracts\HasLabel;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\HasPodName;
use App\Contracts\HasSelectOptions;
use App\Data\ConfigData;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum ServerVariation: string implements AsDependency, HasArtisanCommands, HasCommandOptions, HasDependencies, HasEnvironmentVariables, HasHosts, HasLabel, HasLifecycleHooks, HasPodName, HasSelectOptions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;
    case FPM_NGINX = 'fpm-nginx';
    case FRANKENPHP = 'frankenphp';
    case FPM_APACHE = 'fpm-apache';

    public function getPodName(?ConfigData $config = null): string
    {
        return 'web';
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::FPM_NGINX => 'PHP-FPM + NGINX (Traditional, widely adopted)',
            self::FRANKENPHP => 'FrankenPHP (Laravel Octane, worker mode, HTTP/2 & HTTP/3)',
            self::FPM_APACHE => 'PHP-FPM + Apache (Ideal for WordPress, .htaccess support)',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} server",
            ];
        }

        return $options;
    }

    public function getEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return array_merge(
            $this->getPublicEnvironmentVariables($config, $environment),
            $this->getSecretEnvironmentVariables($config, $environment)
        );
    }

    public function getPublicEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return match ($this) {
            self::FRANKENPHP => [
                'OCTANE_SERVER' => 'frankenphp',
            ],
            default => [],
        };
    }

    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return [];
    }

    public function getHosts(ConfigData $config, string $environment = 'local'): array
    {
        return [];
    }

    public function getDependencies(ConfigData $config): array
    {
        return match ($this) {
            self::FRANKENPHP => [LaravelFeature::OCTANE],
            default => [],
        };
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        return ['web' => 80];
    }

    public function containerPort(): int
    {
        return 8080;
    }

    public function traefikScheme(): string
    {
        return 'http';
    }

    public function getArtisanCommands(?ConfigData $context = null): array
    {
        return match ($this) {
            self::FRANKENPHP => [
                'octane:install --server=frankenphp',
            ],
            default => [],
        };
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        // TODO: Implement onPostInstall() method.
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        return [];
    }

    public function getStartCommand(bool $isLocal): string
    {
        if ($this == self::FRANKENPHP) {
            return '["php", "artisan", "octane:start", "--server=frankenphp", "--port=8080", "--host=0.0.0.0"]';
        }

        return '[]';
    }
}
