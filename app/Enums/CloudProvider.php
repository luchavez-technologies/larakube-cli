<?php

namespace App\Enums;

enum CloudProvider: string
{
    public function label(): string
    {
        return match ($this) {
            self::DO => 'DigitalOcean',
            self::GCP => 'Google Cloud Platform',
            self::AWS => 'Amazon Web Services',
            self::HETZNER => 'Hetzner Cloud',
        };
    }

    /** Map to the corresponding Managed Kubernetes provider enum. */
    public function managedProvider(): ManagedProvider
    {
        return match ($this) {
            self::DO => ManagedProvider::DOKS,
            self::GCP => ManagedProvider::GKE,
            self::AWS => ManagedProvider::EKS,
            default => ManagedProvider::CUSTOM,
        };
    }

    /** Available regions for this provider. */
    public function regions(): array
    {
        return match ($this) {
            self::DO => [
                'nyc1' => 'nyc1  —  New York 1',
                'nyc2' => 'nyc2  —  New York 2',
                'nyc3' => 'nyc3  —  New York 3',
                'sfo2' => 'sfo2  —  San Francisco 2',
                'sfo3' => 'sfo3  —  San Francisco 3',
                'atl1' => 'atl1  —  Atlanta 1',
                'ric1' => 'ric1  —  Richmond 1',
                'tor1' => 'tor1  —  Toronto 1',
                'ams3' => 'ams3  —  Amsterdam 3',
                'lon1' => 'lon1  —  London 1',
                'fra1' => 'fra1  —  Frankfurt 1',
                'sgp1' => 'sgp1  —  Singapore 1',
                'blr1' => 'blr1  —  Bangalore 1',
                'syd1' => 'syd1  —  Sydney 1',
            ],
            self::GCP => [
                'us-central1' => 'us-central1  —  Iowa (Recommended)',
                'us-east1' => 'us-east1  —  South Carolina',
                'us-west1' => 'us-west1  —  Oregon',
                'europe-west1' => 'europe-west1  —  Belgium',
                'europe-west3' => 'europe-west3  —  Frankfurt',
                'europe-west4' => 'europe-west4  —  Netherlands',
                'asia-southeast1' => 'asia-southeast1  —  Singapore',
                'asia-east1' => 'asia-east1  —  Taiwan',
                'asia-northeast1' => 'asia-northeast1  —  Tokyo',
                'australia-southeast1' => 'australia-southeast1  —  Sydney',
            ],
            default => [],
        };
    }

    public function defaultRegion(): string
    {
        return match ($this) {
            self::DO => 'nyc1',
            self::GCP => 'us-central1',
            default => 'us-central1',
        };
    }

    /** Machine sizes available for a standalone VPS instance. */
    public function vpsSizes(): array
    {
        return match ($this) {
            self::DO => [
                's-1vcpu-1gb' => 's-1vcpu-1gb   —  1 vCPU,  1 GB RAM  (~$6/mo)',
                's-1vcpu-2gb' => 's-1vcpu-2gb   —  1 vCPU,  2 GB RAM  (~$12/mo)',
                's-2vcpu-2gb' => 's-2vcpu-2gb   —  2 vCPU,  2 GB RAM  (~$18/mo)',
                's-2vcpu-4gb' => 's-2vcpu-4gb   —  2 vCPU,  4 GB RAM  (~$24/mo)',
                's-4vcpu-8gb' => 's-4vcpu-8gb   —  4 vCPU,  8 GB RAM  (~$48/mo)',
            ],
            self::GCP => [
                'e2-micro' => 'e2-micro   —  2 vCPU,  1 GB RAM  (Free Tier eligible)',
                'e2-small' => 'e2-small   —  2 vCPU,  2 GB RAM  (~$14/mo)',
                'e2-medium' => 'e2-medium  —  2 vCPU,  4 GB RAM  (~$25/mo, Recommended)',
                'e2-standard-2' => 'e2-standard-2 — 2 vCPU,  8 GB RAM  (~$49/mo)',
                'e2-standard-4' => 'e2-standard-4 — 4 vCPU, 16 GB RAM  (~$97/mo)',
            ],
            default => [],
        };
    }

    public function defaultVpsSize(): string
    {
        return match ($this) {
            self::DO => 's-1vcpu-1gb',
            self::GCP => 'e2-medium',
            default => 'e2-medium',
        };
    }

    /** Node sizes available for managed Kubernetes node pools. */
    public function managedSizes(): array
    {
        return match ($this) {
            self::DO => [
                's-1vcpu-2gb' => 's-1vcpu-2gb   —  1 vCPU,  2 GB RAM  (~$12/mo per node)',
                's-2vcpu-2gb' => 's-2vcpu-2gb   —  2 vCPU,  2 GB RAM  (~$18/mo per node)',
                's-2vcpu-4gb' => 's-2vcpu-4gb   —  2 vCPU,  4 GB RAM  (~$24/mo per node)',
                's-4vcpu-8gb' => 's-4vcpu-8gb   —  4 vCPU,  8 GB RAM  (~$48/mo per node)',
                's-8vcpu-16gb' => 's-8vcpu-16gb  —  8 vCPU, 16 GB RAM  (~$96/mo per node)',
            ],
            self::GCP => [
                'e2-medium' => 'e2-medium   —  2 vCPU,  4 GB RAM  (~$25/mo per node)',
                'e2-standard-2' => 'e2-standard-2 — 2 vCPU,  8 GB RAM  (~$49/mo per node)',
                'e2-standard-4' => 'e2-standard-4 — 4 vCPU, 16 GB RAM  (~$97/mo per node)',
            ],
            default => [],
        };
    }

    public function defaultManagedSize(): string
    {
        return match ($this) {
            self::DO => 's-1vcpu-2gb',
            self::GCP => 'e2-medium',
            default => 'e2-medium',
        };
    }

    /** Active providers offered in interactive CLI prompts. */
    public static function activeProviders(): array
    {
        return [
            self::DO->value => self::DO->label(),
            self::GCP->value => self::GCP->label(),
        ];
    }
    case DO = 'do';
    case GCP = 'gcp';
    case AWS = 'aws';
    case HETZNER = 'hetzner';
}
