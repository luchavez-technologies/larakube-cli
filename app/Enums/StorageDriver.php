<?php

namespace App\Enums;

use App\Contracts\AsDependency;
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
use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum StorageDriver: string implements AsDependency, HasCommandOptions, HasComposerDependencies, HasDockerImage, HasEnvironmentVariables, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasSelectOptions
{
    use GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    case MINIO = 'minio';
    case SEAWEEDFS = 'seaweedfs';
    case GARAGE = 'garage';

    public function getPodName(?ConfigData $config = null): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::MINIO => 'MinIO (Classic)',
            self::SEAWEEDFS => 'SeaweedFS (High Performance)',
            self::GARAGE => 'Garage (Modern/Rust)',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} storage",
            ];
        }

        return $options;
    }

    public function port(): int
    {
        return match ($this) {
            self::MINIO => 9000,
            self::SEAWEEDFS => 8333,
            self::GARAGE => 3900,
        };
    }

    public function consolePort(): int
    {
        return match ($this) {
            self::MINIO => 9001,
            self::SEAWEEDFS => 8888,
            self::GARAGE => 3902,
        };
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return match ($this) {
            self::MINIO => 'minio/minio:RELEASE.2025-09-07T16-13-09Z',
            self::SEAWEEDFS => 'chrislusf/seaweedfs:4.20',
            self::GARAGE => 'dxflrs/garage:v2.1.0',
        };
    }

    public function updateK8s(ConfigData $config): void
    {
        $k8sPath = $config->getK8sPath();

        // Write workload
        if ($viewName = $this->getWorkloadViewName()) {
            $dest = $this->getWorkloadYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $content = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $content);
            }
        }

        // Write storage
        if ($viewName = $this->getStorageViewName()) {
            foreach (['local', 'production'] as $env) {
                $dest = "overlays/$env/{$this->getStorageYamlDestination()}";
                if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                    $vols = view($viewName, ['config' => $config, 'driver' => $this, 'environment' => $env])->render();
                    file_put_contents("$k8sPath/overlays/$env/{$this->getStorageYamlDestination()}", $vols);
                }
            }
        }

        // Write network
        if ($viewName = $this->getNetworkViewName()) {
            $dest = $this->getNetworkYamlDestination();
            if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
                $ingress = view($viewName, ['config' => $config, 'driver' => $this])->render();
                file_put_contents("$k8sPath/{$dest}", $ingress);
            }
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return match ($this) {
            self::MINIO => 'k8s.minio.deployment',
            self::SEAWEEDFS => 'k8s.seaweedfs.deployment',
            self::GARAGE => 'k8s.garage.deployment',
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::MINIO => 'base/minio-deployment.yaml',
            self::SEAWEEDFS => 'base/seaweedfs-deployment.yaml',
            self::GARAGE => 'base/garage-deployment.yaml',
        };
    }

    public function getNetworkViewName(): ?string
    {
        return match ($this) {
            self::MINIO => 'k8s.minio.ingress',
            self::SEAWEEDFS => 'k8s.seaweedfs.ingress',
            self::GARAGE => 'k8s.garage.ingress',
        };
    }

    public function getNetworkYamlDestination(): ?string
    {
        return match ($this) {
            self::MINIO => 'overlays/local/minio-ingress.yaml',
            self::SEAWEEDFS => 'overlays/local/seaweedfs-ingress.yaml',
            self::GARAGE => 'overlays/local/garage-ingress.yaml',
        };
    }

    public function getStorageViewName(): ?string
    {
        return match ($this) {
            self::MINIO => 'k8s.minio.volumes',
            self::SEAWEEDFS => 'k8s.seaweedfs.volumes',
            self::GARAGE => 'k8s.garage.volumes',
        };
    }

    public function getStorageYamlDestination(): ?string
    {
        return match ($this) {
            self::MINIO => 'minio-volumes.yaml',
            self::SEAWEEDFS => 'seaweedfs-volumes.yaml',
            self::GARAGE => 'garage-volumes.yaml',
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
        return match ($this) {
            self::MINIO => '["server", "/data", "--console-address", ":9001"]',
            self::SEAWEEDFS => '["server", "-dir=/data", "-s3"]',
            self::GARAGE => '["server"]',
        };
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return ['league/flysystem-aws-s3-v3'];
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        $this->syncEnvFile($projectPath, $this->getEnvironmentVariables($context));

        if ($this === self::GARAGE) {
            // Garage requires explicit key and bucket creation via its CLI.
            // We'll perform this once the infrastructure is up.
            // For now, we'll log it as a post-install instruction or
            // handle it during the first "larakube up".
        }
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
        $s3Host = $config ? $config->getServiceHost('s3', $environment) : 's3.dev.test';

        $envs = [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => 'larakube',
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => 'laravel',
            'AWS_URL' => 'https://'.$s3Host,
            'AWS_TEMPORARY_URL' => 'https://'.$s3Host,
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ];

        // INTERNAL endpoint for PHP pod to talk to storage
        $host = $config ? $config->getInternalFqdn($this, $environment) : $this->getPodName();
        $endpoint = match ($this) {
            self::SEAWEEDFS => "http://{$host}:8333",
            self::MINIO => "http://{$host}:9000",
            self::GARAGE => "http://{$host}:3900",
        };

        $envs['AWS_ENDPOINT'] = $endpoint;

        return $envs;
    }

    public function getSecretEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        return [
            'AWS_SECRET_ACCESS_KEY' => 'larakubesecretpassword',
        ];
    }

    public function getHosts(ConfigData $config, string $environment = 'local'): array
    {
        return match ($this) {
            self::MINIO => [
                $config->getServiceHost('s3', $environment) => 'MinIO S3 API',
                $config->getServiceHost('s3-console', $environment) => 'MinIO Console',
            ],
            self::SEAWEEDFS => [
                $config->getServiceHost('s3', $environment) => 'SeaweedFS S3 API',
                $config->getServiceHost('s3-admin', $environment) => 'SeaweedFS Filer UI',
            ],
            self::GARAGE => [
                $config->getServiceHost('s3', $environment) => 'Garage S3 API',
                $config->getServiceHost('s3-web', $environment) => 'Garage Static Web',
            ],
        };
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        return [$this->getPodName($config) => $this->port()];
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        return match ($this) {
            self::MINIO => [
                'MinIO requires a one-time bucket creation:',
                '1. Visit the Console: https://'.$config->getServiceHost('s3-console'),
                '2. Login with: larakube / larakubesecretpassword',
                '3. Create a bucket named "laravel"',
            ],
            self::SEAWEEDFS => [
                'SeaweedFS requires a one-time bucket creation after "larakube up":',
                '1. Create Bucket: larakube exec --service=seaweedfs "echo s3.bucket.create -name laravel | /usr/bin/weed shell"',
                'You can monitor your storage at: https://'.$config->getServiceHost('s3-admin'),
            ],
            self::GARAGE => [
                'Garage requires a one-time manual initialization after "larakube up":',
                '1. Get Node ID: larakube exec --service=garage "/garage status"',
                '2. Assign Layout: larakube exec --service=garage "/garage layout assign <ID_PREFIX> --capacity 1GB --zone local --tag default"',
                '3. Apply Layout: larakube exec --service=garage "/garage layout apply --version 1"',
                '4. Create Key: larakube exec --service=garage "/garage key create larakube"',
                '5. Create Bucket: larakube exec --service=garage "/garage bucket create laravel"',
                '6. Link Key/Bucket: larakube exec --service=garage "/garage bucket allow --read --write laravel --key larakube"',
                '7. Update your .env: Copy the machine-generated "Key ID" to AWS_ACCESS_KEY_ID and the "Secret key" to AWS_SECRET_ACCESS_KEY.',
                '8. Sync to cluster: larakube up',
            ],
        };
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => [
                basename($this->getWorkloadYamlDestination()),
            ],
            'local' => [
                basename($this->getStorageYamlDestination()),
                basename($this->getNetworkYamlDestination()),
            ],
            'production' => [
                basename($this->getStorageYamlDestination()),
            ],
        ];
    }

    public function getPhpExtensions(): array
    {
        return [];
    }

    public function getDependencies(ConfigData $config): array
    {
        return [];
    }
}
