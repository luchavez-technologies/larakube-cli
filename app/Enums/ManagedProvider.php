<?php

namespace App\Enums;

/**
 * Managed Kubernetes providers. Stored on CloudData::$provider alongside
 * $context, and used to default a sensible per-env storageClass (each provider
 * ships its own dynamic block-storage class).
 */
enum ManagedProvider: string
{
    public function label(): string
    {
        return match ($this) {
            self::DOKS => 'DigitalOcean Kubernetes (DOKS)',
            self::EKS => 'AWS Elastic Kubernetes Service (EKS)',
            self::GKE => 'Google Kubernetes Engine (GKE)',
            self::AKS => 'Azure Kubernetes Service (AKS)',
            self::CIVO => 'Civo Kubernetes',
            self::LKE => 'Linode Kubernetes Engine (LKE)',
            self::CUSTOM => 'Other / custom',
        };
    }

    /** The provider's default dynamic storage class (null = set it yourself). */
    public function defaultStorageClass(): ?string
    {
        return match ($this) {
            self::DOKS => 'do-block-storage',
            self::EKS => 'gp3',
            self::GKE => 'standard',
            self::AKS => 'managed-csi',
            self::CIVO => 'civo-volume',
            self::LKE => 'linode-block-storage',
            self::CUSTOM => null,
        };
    }

    /**
     * Whether this provider offers HA as an opt-in choice, is always-on, or tier-based.
     *
     * - 'boolean'  → simple on/off toggle (DOKS, LKE)
     * - 'tier'     → tiered pricing model (AKS: Free/Standard/Premium)
     * - 'always'   → HA is the default, no choice needed (EKS, GKE, Civo)
     * - 'unknown'  → custom provider, skip the prompt
     */
    public function haOption(): string
    {
        return match ($this) {
            self::DOKS => 'boolean',
            self::LKE => 'boolean',
            self::AKS => 'tier',
            self::EKS, self::GKE, self::CIVO => 'always',
            self::CUSTOM => 'unknown',
        };
    }

    /** Monthly cost of enabling HA (null = included / free / N/A). */
    public function haCost(): ?string
    {
        return match ($this) {
            self::DOKS => '$40/month',
            self::LKE => '$60/month',
            self::AKS => '$73/month (Standard) or $438/month (Premium)',
            default => null,
        };
    }

    /** Whether enabling HA is irreversible on this provider. */
    public function haIrreversible(): bool
    {
        return match ($this) {
            self::DOKS, self::LKE => true,
            default => false,
        };
    }

    case DOKS = 'doks';
    case EKS = 'eks';
    case GKE = 'gke';
    case AKS = 'aks';
    case CIVO = 'civo';
    case LKE = 'lke';
    case CUSTOM = 'custom';
}
