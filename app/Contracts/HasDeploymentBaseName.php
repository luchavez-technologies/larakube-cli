<?php

namespace App\Contracts;

/**
 * The un-suffixed (no "-{instance}") Deployment name for this vendor's
 * single PRIMARY component. Only for non-compound vendors — a compound
 * vendor (implementing HasWorkloadComponents directly) owns its naming
 * entirely inside components() instead.
 */
interface HasDeploymentBaseName
{
    public function baseDeploymentName(): string;
}
