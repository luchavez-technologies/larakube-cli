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
    /** The name as deployed today, category prefix and all. */
    public function baseDeploymentName(): string;

    /**
     * The component itself — `forgejo`, `grafana`, `outline`. Resources are
     * `{component}-{instance}`, with a `-{token}` only when one component
     * owns several of a kind. No category: the instance already identifies
     * which install this is. Used once a tool's live resources have been
     * renamed (see `ClusterTool`'s resourceNaming).
     */
    public function canonicalComponentName(): string;
}
