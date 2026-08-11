<?php

namespace App\Contracts;

interface HasPresenceProbe
{
    /**
     * Kubernetes resource string used to probe if this tool's workload is deployed (e.g. 'deployment/sign-documenso -n larakube-shared').
     */
    public function presenceProbe(?string $instance = null): ?string;
}
