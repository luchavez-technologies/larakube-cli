<?php

namespace App\Contracts;

use App\Data\ClusterToolComponentData;

/**
 * A ClusterTool's set of Deployments, fully resolved for a given
 * instance/engine — exactly one PRIMARY, zero or more INGRESS/WORKER/
 * DATABASE. Single source of truth for {tool}:remove teardown(), the
 * oidc/smtp secondary-deployment patch (sharesPrimarySecret), and dynamic
 * PVC backup discovery — replacing three previously independent,
 * hand-maintained representations of the same compound-tool topology.
 */
interface HasWorkloadComponents
{
    /**
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array;
}
