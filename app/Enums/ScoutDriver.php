<?php

namespace App\Enums;

use App\Contracts\AsDependency;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDependencies;
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
use App\Data\ConfigData;
use App\Traits\DerivesHostsFromServices;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum ScoutDriver: string implements AsDependency, HasCommandOptions, HasComposerDependencies, HasDependencies, HasDockerImage, HasEnvironmentVariables, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasSelectOptions, PlexProvisionable, RemovableWhenManaged
{
    use DerivesHostsFromServices, GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    public function getPodName(?ConfigData $config = null): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::MEILISEARCH => 'Meilisearch (Self-hosted)',
            self::TYPESENSE => 'Typesense (Self-hosted)',
            self::DATABASE => 'Database (No extra infrastructure)',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} for Scout",
            ];
        }

        return $options;
    }

    public function port(): int
    {
        return match ($this) {
            self::MEILISEARCH => 7700,
            self::TYPESENSE => 8108,
            default => 80,
        };
    }

    public function getCompanionDockerImage(): ?string
    {
        return match ($this) {
            self::TYPESENSE => 'typesense/dashboard:latest',
            default => null,
        };
    }

    public function getCompanionPort(): int
    {
        return 80;
    }

    public function hasCompanion(): bool
    {
        return ! is_null($this->getCompanionDockerImage());
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return match ($this) {
            self::MEILISEARCH => 'getmeili/meilisearch:v1.12',
            self::TYPESENSE => 'typesense/typesense:27.1',
            default => '',
        };
    }

    public function getDownloadSize(?ConfigData $config = null): ?int
    {
        return match ($this) {
            self::MEILISEARCH => 40,
            self::TYPESENSE => 45,
            default => null,
        };
    }

    public function getOnDiskSize(?ConfigData $config = null): ?int
    {
        return match ($this) {
            self::MEILISEARCH => 120,
            self::TYPESENSE => 90,
            default => null,
        };
    }

    public function getAllocatedStorage(?ConfigData $config = null): ?string
    {
        return match ($this) {
            self::MEILISEARCH, self::TYPESENSE => '1Gi',
            default => null,
        };
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::MEILISEARCH => ['meilisearch/meilisearch-php'],
            self::TYPESENSE => ['typesense/typesense-php'],
            self::DATABASE => [],
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
            self::MEILISEARCH => [
                'SCOUT_DRIVER' => 'meilisearch',
                'MEILISEARCH_HOST' => 'http://'.($config ? $config->getInternalFqdn($this, $environment) : 'meilisearch').':7700',
            ],
            self::TYPESENSE => [
                'SCOUT_DRIVER' => 'typesense',
                'TYPESENSE_HOST' => $config ? $config->getInternalFqdn($this, $environment) : 'typesense',
                'TYPESENSE_PORT' => '8108',
                'TYPESENSE_PROTOCOL' => 'http',
            ],
            self::DATABASE => [
                'SCOUT_DRIVER' => 'database',
            ],
        };
    }

    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return match ($this) {
            self::MEILISEARCH => [
                'MEILISEARCH_KEY' => 'larakubesecretpassword',
            ],
            self::TYPESENSE => [
                'TYPESENSE_API_KEY' => 'larakubesecretpassword',
            ],
            self::DATABASE => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function getHostServices(): array
    {
        return match ($this) {
            self::MEILISEARCH => ['meilisearch' => 'Meilisearch Console'],
            self::TYPESENSE => [
                'typesense' => 'Typesense API',
                'typesense-dashboard' => 'Typesense Dashboard',
            ],
            default => [],
        };
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        return [$this->getPodName($config) => $this->port()];
    }

    public function getDependencies(ConfigData $config): array
    {
        return [];
    }

    public function updateK8s(ConfigData $config): void
    {
        $k8sPath = $config->getK8sPath();

        if ($viewName = $this->getWorkloadViewName()) {
            $dest = $this->getWorkloadYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $content = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $content);
            }
        }

        if ($viewName = $this->getNetworkViewName()) {
            $dest = $this->getNetworkYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $ingress = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $ingress);
            }
        }

        if ($this->hasCompanion() && $config->withCompanions) {
            $compDest = "base/{$this->value}-companion-deployment.yaml";
            if (! $config->isLocked(".infrastructure/k8s/{$compDest}")) {
                $companion = view('k8s.companion.deployment', ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$compDest}", $companion);
            }

            $ingressDest = "overlays/local/{$this->value}-companion-ingress.yaml";
            if (! $config->isLocked(".infrastructure/k8s/{$ingressDest}")) {
                $ingress = view('k8s.companion.ingress', ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$ingressDest}", $ingress);
            }
        }

        if ($this === self::MEILISEARCH || $this === self::TYPESENSE) {
            foreach (array_merge(['local'], $config->getCloudEnvironments()) as $env) {
                if (in_array($this->value, $config->getManaged($env), true)) {
                    continue;
                }
                @mkdir("$k8sPath/overlays/$env", 0755, true);
                $dest = "overlays/$env/{$this->value}-volumes.yaml";
                if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                    $vols = view("k8s.{$this->value}.volumes", ['config' => $config, 'driver' => $this, 'environment' => $env])->render();
                    file_put_contents("$k8sPath/{$dest}", $vols);
                }
            }
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'k8s.meilisearch.deployment',
            self::TYPESENSE => 'k8s.typesense.deployment',
            default => null,
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'base/meilisearch-deployment.yaml',
            self::TYPESENSE => 'base/typesense-deployment.yaml',
            default => null,
        };
    }

    public function getNetworkViewName(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'k8s.meilisearch.ingress',
            self::TYPESENSE => 'k8s.typesense.ingress',
            default => null,
        };
    }

    public function getNetworkYamlDestination(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'overlays/local/meilisearch-ingress.yaml',
            self::TYPESENSE => 'overlays/local/typesense-ingress.yaml',
            default => null,
        };
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

    public function getStorageViewName(): ?string
    {
        return null;
    }

    public function getStorageYamlDestination(): ?string
    {
        return null;
    }

    public function getManifestFiles(?ConfigData $config = null): array
    {
        $files = [
            'base' => [
                basename($this->getWorkloadYamlDestination()),
            ],
            'local' => [
                basename($this->getNetworkYamlDestination()),
            ],
            'cloud' => [],
        ];

        if ($this === self::MEILISEARCH || $this === self::TYPESENSE) {
            $files['local'][] = "{$this->value}-volumes.yaml";
            $files['cloud'][] = "{$this->value}-volumes.yaml";
        }

        if ($this->hasCompanion() && ($config?->withCompanions ?? true)) {
            $files['base'][] = "{$this->value}-companion-deployment.yaml";
            $files['local'][] = "{$this->value}-companion-ingress.yaml";
        }

        return $files;
    }

    public function getManagedResources(ConfigData $config): array
    {
        return match ($this) {
            self::MEILISEARCH, self::TYPESENSE => [
                ['kind' => 'Deployment', 'name' => $this->getPodName($config)],
                ['kind' => 'Service', 'name' => $this->getPodName($config)],
            ],
            default => [],
        };
    }

    public function getPhpExtensions(): array
    {
        return [];
    }

    public function isPlexReady(): bool
    {
        // Meilisearch is the Commons-provisionable search engine today. Typesense
        // is mapped but not yet wired; the database driver is never shared.
        return $this === self::MEILISEARCH;
    }

    public function commonsServiceName(): ?string
    {
        // The Commons service name IS the driver value — no remapping. The
        // database-backed scout runs in the app's own DB, so it's not shareable.
        return $this === self::DATABASE ? null : $this->value;
    }

    case MEILISEARCH = 'meilisearch';
    case TYPESENSE = 'typesense';
    case DATABASE = 'database';
}
