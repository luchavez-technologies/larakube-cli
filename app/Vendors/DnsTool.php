<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasImages;
use App\Tools\Concerns\PinsImages;

/** The single vendor backing the DNS category — 'Automated DNS'. Only ExternalDNS. */
final class DnsTool implements ClusterToolVendor, HasDeploymentBaseName, HasImages
{
    use PinsImages;

    /**
     * The images this tool's templates deploy, for its LaraKube Cloud release. Must match the
     * literal pins in resources/views/k8s/external-dns; TemplateImagesInSyncTest checks it.
     *
     * @return array<string, string>
     */
    public function images(): array
    {
        return [
            'external-dns' => 'registry.k8s.io/external-dns/external-dns:v0.24.0',
        ];
    }

    public function getLabel(): string
    {
        return 'ExternalDNS';
    }

    public function baseDeploymentName(): string
    {
        return 'external-dns';
    }

    public function canonicalComponentName(): string
    {
        return 'external-dns';
    }
}
