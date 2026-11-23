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
use App\Contracts\HasPromptableHosts;
use App\Contracts\HasSelectOptions;
use App\Contracts\PlexProvisionable;
use App\Contracts\RemovableWhenManaged;
use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Traits\DerivesHostsFromServices;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum StorageDriver: string implements AsDependency, HasCommandOptions, HasComposerDependencies, HasDockerImage, HasEnvironmentVariables, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasPodName, HasPromptableHosts, HasSelectOptions, PlexProvisionable, RemovableWhenManaged
{
    use DerivesHostsFromServices, GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    public function getPodName(?ConfigData $config = null): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
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
            self::SEAWEEDFS => 8333,
            self::GARAGE => 3900,
        };
    }

    public function consolePort(): int
    {
        return match ($this) {
            self::SEAWEEDFS => 8888,
            self::GARAGE => 3902,
        };
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return match ($this) {
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
            foreach (array_merge(['local'], $config->getCloudEnvironments()) as $env) {
                if (in_array($this->value, $config->getExternallyHosted($env), true)) {
                    continue;
                }
                @mkdir("$k8sPath/overlays/$env", 0755, true);
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
            self::SEAWEEDFS => 'k8s.seaweedfs.deployment',
            self::GARAGE => 'k8s.garage.deployment',
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::SEAWEEDFS => 'base/seaweedfs-deployment.yaml',
            self::GARAGE => 'base/garage-deployment.yaml',
        };
    }

    public function getNetworkViewName(): ?string
    {
        return match ($this) {
            self::SEAWEEDFS => 'k8s.seaweedfs.ingress',
            self::GARAGE => 'k8s.garage.ingress',
        };
    }

    public function getNetworkYamlDestination(): ?string
    {
        return match ($this) {
            self::SEAWEEDFS => 'overlays/local/seaweedfs-ingress.yaml',
            self::GARAGE => 'overlays/local/garage-ingress.yaml',
        };
    }

    public function getStorageViewName(): ?string
    {
        return match ($this) {
            self::SEAWEEDFS => 'k8s.seaweedfs.volumes',
            self::GARAGE => 'k8s.garage.volumes',
        };
    }

    public function getStorageYamlDestination(): ?string
    {
        return match ($this) {
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
        // See DatabaseDriver::onPostInstall() — the Commons owns these once joined.
        if (! $context?->isPlexBacked($this, 'local')) {
            $this->syncEnvFile($projectPath, $this->getEnvironmentVariables($context));
        }

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
            $this->getSecretEnvironmentVariables($config, $environment),
        );
    }

    public function getPublicEnvironmentVariables(?ConfigData $config = null, string $environment = 'local'): array
    {
        $s3Host = $config ? $config->getServiceHost('s3', $environment) : 's3.'.GlobalConfigData::load()->getLocalTld();
        $bucket = 'laravel';

        $envs = [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => 'larakube',
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => $bucket,
            // Path-style URLs need the bucket IN the path (host alone 404s/denies
            // — there's no bucket literally named after the object's key prefix).
            'AWS_URL' => 'https://'.$s3Host.'/'.$bucket,
            'AWS_TEMPORARY_URL' => 'https://'.$s3Host.'/'.$bucket,
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ];

        // INTERNAL endpoint for PHP pod to talk to storage
        $host = $config ? $config->getInternalFqdn($this, $environment) : $this->getPodName();
        $endpoint = match ($this) {
            self::SEAWEEDFS => "http://{$host}:8333",
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

    /**
     * @return array<string, string>
     */
    public function getHostServices(): array
    {
        return match ($this) {
            self::SEAWEEDFS => [
                's3' => 'SeaweedFS S3 API',
                's3-admin' => 'SeaweedFS Filer UI',
            ],
            self::GARAGE => [
                's3' => 'Garage S3 API',
                's3-web' => 'Garage Static Web',
            ],
        };
    }

    /**
     * The S3 API endpoint is the client-facing one worth a vanity host
     * (e.g. cdn.example.com); the admin console/filer UI is not prompted.
     *
     * @return array<string, string>
     */
    public function getPromptableHostServices(): array
    {
        return match ($this) {
            self::SEAWEEDFS => ['s3' => 'SeaweedFS S3 API'],
            self::GARAGE => ['s3' => 'Garage S3 API'],
        };
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        return [$this->getPodName($config) => $this->port()];
    }

    public function getPostInstallInstructions(?ConfigData $config = null): array
    {
        return match ($this) {
            self::SEAWEEDFS => [
                'SeaweedFS requires a one-time bucket creation after "larakube up":',
                '1. Open a shell in the SeaweedFS pod:',
                '     larakube shell seaweedfs',
                '2. Create Bucket: inside the shell, run:',
                '     echo "s3.bucket.create -name laravel" | weed shell',
                'You can monitor your storage at: https://'.$config->getServiceHost('s3-admin'),
                'To make it public, SeaweedFS has no single-command bucket ACL — check the exact',
                'subcommand for your version inside `larakube shell seaweedfs` by running:',
                '     echo "help" | weed shell',
                '(look for an s3.bucket.policy / s3.configure entry), or configure anonymous access via the Filer UI above.',
                $this->temporaryUrlTip(),
            ],
            self::GARAGE => [
                'Garage requires a one-time manual initialization after "larakube up":',
                '1. Open a shell in the Garage pod:',
                '     larakube shell garage',
                '2. Inside the shell, run the following commands in order:',
                '     # Get Node ID to copy and use in the next step (use the prefix of the ID)',
                '     /garage status',
                '     /garage layout assign <ID_PREFIX> --capacity 1GB --zone local --tag default',
                '     /garage layout apply --version 1',
                '     /garage key create larakube',
                '     /garage bucket create laravel',
                '     /garage bucket allow --read --write laravel --key larakube',
                '3. Update your .env: Copy the machine-generated "Key ID" to AWS_ACCESS_KEY_ID and the "Secret key" to AWS_SECRET_ACCESS_KEY.',
                '4. Sync to cluster: larakube up',
                '5. Garage has no bucket ACL — publish it as a static website instead to make it public (inside the shell):',
                '     /garage bucket website --allow laravel',
                '   Files are then served, unauthenticated, from: https://'.$config->getServiceHost('s3-web'),
                $this->temporaryUrlTip(),
            ],
        };
    }

    public function getManifestFiles(?ConfigData $config = null): array
    {
        $files = [
            'base' => [
                basename($this->getWorkloadYamlDestination()),
            ],
            'local' => [
                basename($this->getStorageYamlDestination()),
                basename($this->getNetworkYamlDestination()),
            ],
            'cloud' => [
                basename($this->getStorageYamlDestination()),
            ],
        ];

        return $files;
    }

    public function getManagedResources(ConfigData $config): array
    {
        $name = $this->getPodName($config);

        return [
            ['kind' => 'Deployment', 'name' => $name],
            ['kind' => 'Service', 'name' => $name],
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

    public function isPlexReady(): bool
    {
        // Wired Commons S3 backends (deployment + per-tenant bucket provisioning
        // via commonsBucketCreateCommand). Garage uses a shared "commons-admin"
        // key created once at Commons bootstrap (see PlexInitCommand) instead of
        // a single root credential like SeaweedFS.
        return match ($this) {
            self::SEAWEEDFS, self::GARAGE => true,
            default => false,
        };
    }

    /**
     * Whether plex:migrate can copy this backend's existing bucket into the
     * Commons (a live network mirror — no local staging file, unlike the
     * database dump/restore pair). Independent of isPlexReady(): a backend
     * can be join-ready (fresh empty bucket) without a migrate path yet.
     */
    public function isMigratable(): bool
    {
        return false;
    }

    /**
     * Shell command — run via `kubectl exec` INSIDE the SELF-HOSTED pod —
     * that mirrors this driver's self-hosted "laravel" bucket into a Commons
     * tenant bucket. Null for engines without a migrate path yet.
     */
    public function selfHostedMirrorCommand(string $targetBucket, string $commonsHost, string $accessKey, string $secretKey): ?string
    {
        return null;
    }

    /**
     * Inverse of selfHostedMirrorCommand() — run via `kubectl exec` INSIDE
     * the SELF-HOSTED pod, pulls a Commons tenant bucket's contents BACK into
     * this driver's self-hosted "laravel" bucket. Used by plex:leave
     * --restore once a self-hosted pod exists again to receive the data.
     */
    public function commonsToSelfHostedMirrorCommand(string $sourceBucket, string $commonsHost, string $accessKey, string $secretKey): ?string
    {
        return null;
    }

    /**
     * Shell command — run via `kubectl exec deploy/<value> -- sh -c '<this>'` —
     * that idempotently creates a tenant's bucket on this Commons S3 backend.
     * Bucket-per-tenant isolation under the shared admin key. Garage has no
     * single root credential — instead every tenant bucket is explicitly granted
     * to the one shared "commons-admin" key created at bootstrap (see PlexInitCommand),
     * referenced by NAME (Garage resolves an unambiguous key name to its id) so this
     * command never needs to know the generated key id/secret itself. `;`
     * (not `&&`) between steps: a bucket that already exists makes `create`
     * exit non-zero, but `allow` must still run so re-provisioning stays
     * idempotent — the final exit code reflects `allow`, the real outcome.
     */
    public function commonsBucketCreateCommand(string $bucket): string
    {
        return match ($this) {
            self::SEAWEEDFS => "echo 's3.bucket.create -name {$bucket}' | weed shell",
            self::GARAGE => "/garage bucket create {$bucket} 2>/dev/null; ".
                "/garage bucket allow --read --write {$bucket} --key commons-admin",
        };
    }

    /**
     * Inverse of commonsBucketCreateCommand — drops a tenant's bucket (the
     * plex:leave/remove teardown). Same sh -c invocation contract.
     */
    public function commonsBucketDeleteCommand(string $bucket): string
    {
        return match ($this) {
            self::SEAWEEDFS => "echo 's3.bucket.delete -name {$bucket}' | weed shell",
            self::GARAGE => "/garage bucket deny --read --write {$bucket} --key commons-admin 2>/dev/null; ".
                "/garage bucket delete --yes {$bucket}",
        };
    }

    public function commonsBucketListCommand(): string
    {
        return match ($this) {
            self::SEAWEEDFS => "echo 's3.bucket.list' | weed shell",
            self::GARAGE => '/garage bucket list',
        };
    }

    public function commonsObjectListCommand(string $bucket, ?string $path = null): string
    {
        $sub = $path !== null && $path !== '' ? '/'.ltrim($path, '/') : '';

        return match ($this) {
            self::SEAWEEDFS => "echo 'fs.ls /buckets/'.$bucket.$sub | weed shell",
            self::GARAGE => "/garage bucket info {$bucket}",
        };
    }

    public function commonsServiceName(): ?string
    {
        // Each S3 backend is its own Commons service (keyed by value), so several
        // can coexist when different tenants declare different backends.
        return $this->value;
    }

    /**
     * One-line pointer to the docs instead of printing the full
     * Storage::temporaryUrl()-needs-a-separate-disk walkthrough inline — that
     * used to make `larakube up`'s output look like it had spat out an error.
     * Every StorageDriver shares the same AWS_ENDPOINT (internal)/AWS_URL
     * (public) split in getPublicEnvironmentVariables(), so the gotcha (and
     * its fix) is identical regardless of backend; the full walkthrough lives
     * at docs/docs/storage/overview.md.
     */
    private function temporaryUrlTip(): string
    {
        return 'Tip: Storage::temporaryUrl() needs a small config change to work outside the cluster — see https://docs.larakube.app/docs/storage/overview#temporary-urls-need-a-separate-disk';
    }

    case SEAWEEDFS = 'seaweedfs';
    case GARAGE = 'garage';
}
