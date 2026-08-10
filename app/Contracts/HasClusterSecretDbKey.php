<?php

namespace App\Contracts;

/**
 * Overrides the default `{TENANT}_DB_PASSWORD` Kubernetes Secret key naming
 * for a vendor whose Commons database password key doesn't follow that
 * convention (e.g. Stalwart's `STALWART_STORE_PASSWORD`).
 */
interface HasClusterSecretDbKey
{
    public function clusterSecretDbKey(string $tenant): string;
}
