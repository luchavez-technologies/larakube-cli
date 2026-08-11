<?php

namespace App\Contracts;

interface HasVpnWiring
{
    /**
     * Target deployment and middleware metadata for NetBird VPN mesh restriction.
     *
     * @return array{name: string, namespace: string}|null
     */
    public function vpnMiddlewareTarget(?string $instance = null): ?array;
}
