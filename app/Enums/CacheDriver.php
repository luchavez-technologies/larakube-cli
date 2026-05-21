<?php

namespace App\Enums;

use App\Contracts\AsDependency;
use App\Contracts\HasArtisanCommands;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDockerImage;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHosts;
use App\Contracts\HasKubernetesFiles;
use App\Contracts\HasLabel;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\HasPodName;
use App\Contracts\HasSelectOptions;
use App\Contracts\RequiresPhpExtensions;
use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum CacheDriver: string implements AsDependency, HasArtisanCommands, HasCommandOptions, HasComposerDependencies, HasDockerImage, HasEnvironmentVariables, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasSelectOptions, RequiresPhpExtensions
{
    use GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    case REDIS = 'redis';
    case MEMCACHED = 'memcached';
    case DATABASE = 'database';

    public function getPodName(?ConfigData $config = null): string
    {
        return $this->value;
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::REDIS => 'Redis',
            self::MEMCACHED => 'Memcached',
            self::DATABASE => 'Database (uses your primary DB)',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} for caching",
            ];
        }

        return $options;
    }

    public function updateK8s(ConfigData $config): void
    {
        if ($this === self::DATABASE) {
            return;
        }

        $k8sPath = $config->getK8sPath();

        // Write workload
        if ($viewName = $this->getWorkloadViewName()) {
            $dest = $this->getWorkloadYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $content = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $content);
            }
        }

        // Write volumes (Memcached doesn't usually need persistent volumes in local dev, same as Redis here)
        if ($viewName = $this->getStorageViewName()) {
            $storageDest = $this->getStorageYamlDestination();
            $vols = view($viewName, ['config' => $config, 'driver' => $this])->render();

            foreach ($config->getEnvironments() as $env) {
                $dest = "overlays/$env/{$storageDest}";
                if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                    file_put_contents("$k8sPath/{$dest}", $vols);
                }
            }
        }

        // Write for local only
        if ($viewName = $this->getPatchViewName()) {
            $dest = $this->getPatchYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $patch = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $patch);
            }
        }

        // Write companion manifests (Local only)
        if ($this->hasCompanion()) {
            $compDest = "overlays/local/{$this->value}-companion.yaml";
            if (! $config->isLocked(".infrastructure/k8s/{$compDest}")) {
                $content = view('k8s.companion.deployment', ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$compDest}", $content);
            }

            $ingressDest = "overlays/local/{$this->value}-companion-ingress.yaml";
            if (! $config->isLocked(".infrastructure/k8s/{$ingressDest}")) {
                $ingress = view('k8s.companion.ingress', ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$ingressDest}", $ingress);
            }
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return match ($this) {
            self::REDIS => 'k8s.redis.deployment',
            self::MEMCACHED => 'k8s.memcached.deployment',
            default => null,
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::REDIS => 'base/redis-deployment.yaml',
            self::MEMCACHED => 'base/memcached-deployment.yaml',
            default => null,
        };
    }

    public function getNetworkViewName(): ?string
    {
        return null;
    }

    public function getNetworkYamlDestination(): ?string
    {
        return null;
    }

    public function getStorageViewName(): ?string
    {
        return null;
    }

    public function getStorageYamlDestination(): ?string
    {
        return null;
    }

    public function getPatchViewName(): ?string
    {
        return null;
    }

    public function getPatchYamlDestination(): ?string
    {
        return null;
    }

    public function getK8sDeploymentArgs(): string
    {
        return '';
    }

    public function getManifestFiles(): array
    {
        $manifests = match ($this) {
            self::REDIS => [
                'base' => ['redis-deployment.yaml'],
            ],
            self::MEMCACHED => [
                'base' => ['memcached-deployment.yaml'],
            ],
            default => [],
        };

        if ($this->hasCompanion()) {
            $manifests['local'][] = "{$this->value}-companion.yaml";
            $manifests['local'][] = "{$this->value}-companion-ingress.yaml";
        }

        return $manifests;
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return match ($this) {
            self::REDIS => 'redis:7.4',
            self::MEMCACHED => 'memcached:1.6-alpine',
            default => '',
        };
    }

    public function getCompanionDockerImage(): ?string
    {
        return match ($this) {
            self::REDIS => 'rediscommander/redis-commander:latest',
            default => null,
        };
    }

    public function getCompanionPort(): int
    {
        return match ($this) {
            self::REDIS => 8081,
            default => 80,
        };
    }

    public function hasCompanion(): bool
    {
        return ! is_null($this->getCompanionDockerImage());
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
            self::REDIS => [
                'REDIS_HOST' => $config ? $config->getInternalFqdn($this, $environment) : 'redis',
                'CACHE_STORE' => 'redis',
                'SESSION_DRIVER' => 'redis',
                'QUEUE_CONNECTION' => 'redis',
                'APP_MAINTENANCE_DRIVER' => 'cache',
                'APP_MAINTENANCE_STORE' => 'redis',
            ],
            self::MEMCACHED => [
                'MEMCACHED_HOST' => $config ? $config->getInternalFqdn($this, $environment) : 'memcached',
                'CACHE_STORE' => 'memcached',
                'SESSION_DRIVER' => 'memcached',
            ],
            self::DATABASE => [
                'CACHE_STORE' => 'database',
                'SESSION_DRIVER' => 'database',
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
        $appName = $config->getName();

        return match ($this) {
            self::REDIS => ["redis-{$appName}.dev.test" => 'Redis Console'],
            self::MEMCACHED => ["memcached-{$appName}.dev.test" => 'Memcached Console'],
            default => [],
        };
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        if ($this === self::DATABASE) {
            return [];
        }

        return [$this->dbHost() => $this->dbPort()];
    }

    public function dbConnection(): string
    {
        return match ($this) {
            self::REDIS => 'redis',
            self::MEMCACHED => 'memcached',
            default => '',
        };
    }

    public function dbHost(): string
    {
        return $this->getPodName();
    }

    public function dbPort(): int
    {
        return match ($this) {
            self::REDIS => 6379,
            self::MEMCACHED => 11211,
            default => 0,
        };
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return [];
    }

    public function getPhpExtensions(): array
    {
        return match ($this) {
            self::MEMCACHED => ['memcached'],
            default => [],
        };
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        $this->syncEnvFile($projectPath, $this->getEnvironmentVariables($context));
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        return [];
    }

    public function getCommandOption(): ?string
    {
        return match ($this) {
            self::REDIS => 'redis',
            self::MEMCACHED => 'memcached',
            self::DATABASE => 'database',
        };
    }

    public function getArtisanCommands(?ConfigData $context = null): array
    {
        return match ($this) {
            self::DATABASE => ['cache:table'],
            default => [],
        };
    }
}
