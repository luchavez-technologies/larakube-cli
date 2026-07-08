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
use App\Contracts\PlexProvisionable;
use App\Contracts\RemovableWhenManaged;
use App\Contracts\RequiresPhpExtensions;
use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum CacheDriver: string implements AsDependency, HasArtisanCommands, HasCommandOptions, HasComposerDependencies, HasDockerImage, HasEnvironmentVariables, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasSelectOptions, PlexProvisionable, RemovableWhenManaged, RequiresPhpExtensions
{
    use GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

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

            foreach ($config->getEnvironments() as $env) {
                $dest = "overlays/$env/{$storageDest}";
                if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                    $vols = view($viewName, ['config' => $config, 'driver' => $this, 'environment' => $env])->render();
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

    public function getManifestFiles(?ConfigData $config = null): array
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

        return $manifests;
    }

    public function getManagedResources(ConfigData $config): array
    {
        return match ($this) {
            self::REDIS, self::MEMCACHED => [
                ['kind' => 'Deployment', 'name' => $this->getPodName($config)],
                ['kind' => 'Service', 'name' => $this->getPodName($config)],
            ],
            default => [],
        };
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return match ($this) {
            self::REDIS => 'redis:7.4',
            self::MEMCACHED => 'memcached:1.6-alpine',
            default => '',
        };
    }

    public function getDownloadSize(?ConfigData $config = null): ?int
    {
        return match ($this) {
            self::REDIS => 32,
            self::MEMCACHED => 8,
            default => null,
        };
    }

    public function getOnDiskSize(?ConfigData $config = null): ?int
    {
        return match ($this) {
            self::REDIS => 120,
            self::MEMCACHED => 15,
            default => null,
        };
    }

    public function getAllocatedStorage(?ConfigData $config = null): ?string
    {
        return null;
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
        return match ($this) {
            self::REDIS => [
                'REDIS_HOST' => $config ? $config->getInternalFqdn($this, $environment) : 'redis',
                'REDIS_PORT' => (string) $this->dbPort(),
                'CACHE_STORE' => 'redis',
                'SESSION_DRIVER' => 'redis',
                'QUEUE_CONNECTION' => 'redis',
                'APP_MAINTENANCE_DRIVER' => 'cache',
                'APP_MAINTENANCE_STORE' => 'redis',
            ],
            self::MEMCACHED => [
                'MEMCACHED_HOST' => $config ? $config->getInternalFqdn($this, $environment) : 'memcached',
                'MEMCACHED_PORT' => (string) $this->dbPort(),
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
        // Caches publish no ingress host of their own. Admin consoles are no
        // longer per-project — they're the shared CompanionDriver apps in
        // larakube-companions (redisinsight.kube, etc.), surfaced via
        // ManagesCompanions, not a `redis.<project>.kube` route here.
        return [];
    }

    public function getHostServices(): array
    {
        return [];
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

    public function isPlexReady(): bool
    {
        // Redis is the Commons-provisionable cache today. Memcached is mapped
        // but not yet wired; the database driver is never a shared service.
        return $this === self::REDIS;
    }

    public function commonsServiceName(): ?string
    {
        // The Commons service name IS the driver value — no remapping. The
        // database-backed cache runs in the app's own DB, so it's not shareable.
        return $this === self::DATABASE ? null : $this->value;
    }

    public function exporterImage(): ?string
    {
        return match ($this) {
            self::REDIS => 'oliver006/redis_exporter:v1.63.0',
            default => null,
        };
    }

    public function exporterPort(): int
    {
        return match ($this) {
            self::REDIS => 9121,
            default => 0,
        };
    }

    case REDIS = 'redis';
    case MEMCACHED = 'memcached';
    case DATABASE = 'database';
}
