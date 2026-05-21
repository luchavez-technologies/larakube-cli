<?php

namespace App\Enums;

use App\Contracts\AsDependency;
use App\Contracts\HasArtisanCommands;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDockerImage;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHiddenComponents;
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

enum DatabaseDriver: string implements AsDependency, HasArtisanCommands, HasCommandOptions, HasComposerDependencies, HasDockerImage, HasEnvironmentVariables, HasHiddenComponents, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasSelectOptions, RequiresPhpExtensions
{
    use GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    case MYSQL = 'mysql';
    case MARIADB = 'mariadb';
    case POSTGRESQL = 'postgres';
    case MONGODB = 'mongodb';
    case SQLITE = 'sqlite';

    public function getPodName(?ConfigData $config = null): string
    {
        return $this->value;
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MYSQL => 'MySQL',
            self::MARIADB => 'MariaDB',
            self::POSTGRESQL => 'PostgreSQL',
            self::MONGODB => 'MongoDB',
            self::SQLITE => 'SQLite (Local File)',
        };
    }

    public function isHidden(?ConfigData $config = null): bool
    {
        return $this === self::SQLITE && $config?->getServerVariation() === ServerVariation::FRANKENPHP;
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} database",
            ];
        }

        return $options;
    }

    public static function getDatabases(bool $asValues = false): array
    {
        $databases = [];

        foreach (self::cases() as $case) {
            $databases[] = $asValues ? $case->value : $case;
        }

        return $databases;
    }

    public function updateK8s(ConfigData $config): void
    {
        if ($this === self::SQLITE) {
            return;
        }

        $k8sPath = $config->getK8sPath();

        // Write workload
        $viewName = $this->getWorkloadViewName();
        $dest = $this->getWorkloadYamlDestination();
        if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
            $content = view($viewName, ['config' => $config, 'driver' => $this])->render();
            file_put_contents("$k8sPath/{$dest}", $content);
        }

        // Write volumes
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
            self::MYSQL => 'k8s.mysql.deployment',
            self::MARIADB => 'k8s.mariadb.deployment',
            self::POSTGRESQL => 'k8s.postgres.deployment',
            self::MONGODB => 'k8s.mongodb.statefulset',
            self::SQLITE => null,
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::MYSQL => 'base/mysql-deployment.yaml',
            self::MARIADB => 'base/mariadb-deployment.yaml',
            self::POSTGRESQL => 'base/postgres-deployment.yaml',
            self::MONGODB => 'base/mongodb-statefulset.yaml',
            self::SQLITE => null,
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
        return match ($this) {
            self::MYSQL => 'k8s.mysql.volumes',
            self::MARIADB => 'k8s.mariadb.volumes',
            self::POSTGRESQL => 'k8s.postgres.volumes',
            default => null,
        };
    }

    public function getStorageYamlDestination(): ?string
    {
        return match ($this) {
            self::MYSQL => 'mysql-volumes.yaml',
            self::MARIADB => 'mariadb-volumes.yaml',
            self::POSTGRESQL => 'postgres-volumes.yaml',
            default => null,
        };
    }

    public function getPatchViewName(): ?string
    {
        return match ($this) {
            self::MYSQL => 'k8s.mysql.patch',
            self::MARIADB => 'k8s.mariadb.patch',
            self::POSTGRESQL => 'k8s.postgres.patch',
            default => null,
        };
    }

    public function getPatchYamlDestination(): ?string
    {
        return match ($this) {
            self::MYSQL => 'overlays/local/mysql-patch.yaml',
            self::MARIADB => 'overlays/local/mariadb-patch.yaml',
            self::POSTGRESQL => 'overlays/local/postgres-patch.yaml',
            default => null,
        };
    }

    public function getK8sDeploymentArgs(): string
    {
        return '';
    }

    public function getManifestFiles(): array
    {
        $manifests = match ($this) {
            self::MYSQL => [
                'base' => ['mysql-deployment.yaml'],
                'local' => ['mysql-volumes.yaml'],
                'production' => ['mysql-volumes.yaml'],
                'patches' => ['mysql-patch.yaml'],
            ],
            self::MARIADB => [
                'base' => ['mariadb-deployment.yaml'],
                'local' => ['mariadb-volumes.yaml'],
                'production' => ['mariadb-volumes.yaml'],
                'patches' => ['mariadb-patch.yaml'],
            ],
            self::POSTGRESQL => [
                'base' => ['postgres-deployment.yaml'],
                'local' => ['postgres-volumes.yaml'],
                'production' => ['postgres-volumes.yaml'],
                'patches' => ['postgres-patch.yaml'],
            ],
            self::MONGODB => [
                'base' => ['mongodb-statefulset.yaml'],
            ],
            self::SQLITE => [],
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
            self::MYSQL => 'mysql:8.4',
            self::MARIADB => 'mariadb:11.8',
            self::POSTGRESQL => ($config?->hasFeature(LaravelFeature::AI)) ? 'pgvector/pgvector:pg17' : 'postgres:17.9',
            self::MONGODB => 'mongo:7.0',
            self::SQLITE => '',
        };
    }

    public function getCompanionDockerImage(): ?string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'phpmyadmin:latest',
            self::POSTGRESQL => 'adminer:latest',
            self::MONGODB => 'mongo-express:latest',
            self::SQLITE => null,
            default => null,
        };
    }

    public function getCompanionPort(): int
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 80,
            self::POSTGRESQL => 8080,
            self::MONGODB => 8081,
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
        if ($this === self::SQLITE) {
            return [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => '/var/lib/larakube/database.sqlite',
                'AUTORUN_LARAVEL_MIGRATION_SKIP_DB_CHECK' => 'true',
            ];
        }

        $envs = [
            'DB_CONNECTION' => $this->dbConnection(),
            'DB_HOST' => $config ? $config->getInternalFqdn($this, $environment) : $this->dbHost(),
            'DB_PORT' => (string) $this->dbPort(),
            'DB_DATABASE' => 'laravel',
            'DB_USERNAME' => $this->dbUsername(),
        ];

        if ($this === self::MONGODB) {
            $envs['AUTORUN_LARAVEL_MIGRATION_SKIP_DB_CHECK'] = 'true';
        }

        return $envs;
    }

    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        if ($this === self::SQLITE) {
            return [];
        }

        $envs = [
            'DB_PASSWORD' => 'larakubesecretpassword',
        ];

        if ($this === self::MONGODB) {
            $host = $config ? $config->getInternalFqdn($this, $environment) : 'mongodb';
            $envs['DB_URI'] = "mongodb://root:larakubesecretpassword@{$host}:27017/laravel?authSource=admin";
        }

        return $envs;
    }

    public function getHosts(ConfigData $config, string $environment = 'local'): array
    {
        $appName = $config->getName();

        return match ($this) {
            self::MYSQL => ["mysql-{$appName}.dev.test" => 'MySQL Console'],
            self::MARIADB => ["mariadb-{$appName}.dev.test" => 'MariaDB Console'],
            self::POSTGRESQL => ["postgres-{$appName}.dev.test" => 'PostgreSQL Console'],
            self::MONGODB => ["mongodb-{$appName}.dev.test" => 'MongoDB Console'],
            self::SQLITE => [],
            default => [],
        };
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        if ($this === self::SQLITE) {
            return [];
        }

        return [$this->dbHost() => $this->dbPort()];
    }

    public function dbConnection(): string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'mysql',
            self::POSTGRESQL => 'pgsql',
            self::MONGODB => 'mongodb',
            self::SQLITE => 'sqlite',
        };
    }

    public function dbHost(): string
    {
        return $this->getPodName();
    }

    public function dbPort(): int
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 3306,
            self::POSTGRESQL => 5432,
            self::MONGODB => 27017,
            self::SQLITE => 0,
        };
    }

    public function dbUsername(): ?string
    {
        return match ($this) {
            self::MYSQL, self::MARIADB => 'laravel',
            self::POSTGRESQL => 'postgres',
            self::MONGODB => 'root',
            self::SQLITE => null,
            default => null,
        };
    }

    public function isExternal(): bool
    {
        return $this !== self::SQLITE;
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::MONGODB => ['mongodb/laravel-mongodb'],
            default => [],
        };
    }

    public function getPhpExtensions(): array
    {
        return match ($this) {
            self::MONGODB => ['mongodb'],
            default => [],
        };
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        $this->syncEnvFile($projectPath, $this->getEnvironmentVariables($context));

        if ($this === self::MONGODB) {
            $configPath = "$projectPath/config/database.php";
            if (file_exists($configPath)) {
                $content = file_get_contents($configPath);

                if (! str_contains($content, "'mongodb'")) {
                    $stub = "
        'mongodb' => [
            'driver' => 'mongodb',
            'dsn' => env('DB_URI', 'mongodb://127.0.0.1:27017/laravel'),
            'database' => env('DB_DATABASE', 'laravel'),
        ],\n";

                    // Inject before sqlsrv or before the end of the connections array
                    if (str_contains($content, "'sqlsrv' => [")) {
                        $content = str_replace("'sqlsrv' => [", $stub."\n        'sqlsrv' => [", $content);
                    } else {
                        // Fallback: search for the end of the connections array
                        $content = preg_replace("/('connections' => \[.*?)(\n\s+\])/s", "$1$stub$2", $content);
                    }

                    file_put_contents($configPath, $content);
                }
            }
        }
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        return match ($this) {
            self::MONGODB => [
                'MongoDB is a schema-less document store. Integration with Laravel requires specific code changes:',
                '1. IMPORTANT: Follow the Official Guide: https://www.mongodb.com/docs/drivers/php/laravel-mongodb/current/',
                '2. Remove positional methods like "->after(\'column\')" from all migration files.',
                '3. Update your Models (including User.php) to extend "MongoDB\Laravel\Eloquent\Model".',
                '4. Ensure your Authenticatable models (User.php) use "MongoDB\Laravel\Auth\User" as a base.',
            ],
            default => [],
        };
    }

    public function getCommandOption(): ?string
    {
        return match ($this) {
            self::MYSQL => 'mysql',
            self::MARIADB => 'mariadb',
            self::POSTGRESQL => 'postgres',
            self::MONGODB => 'mongodb',
            self::SQLITE => 'sqlite',
        };
    }

    public function getArtisanCommands(?ConfigData $context = null): array
    {
        return [];
    }
}
