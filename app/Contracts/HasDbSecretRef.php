<?php

namespace App\Contracts;

/**
 * The Kubernetes Secret + key holding this vendor's Commons database
 * password, for secrets:wire to hand over to OpenBao static-role rotation.
 * Absent (no implementing interface) for vendors with no simple single-key
 * password (e.g. one baked into a composed connection URL, or no Commons DB
 * at all).
 */
interface HasDbSecretRef
{
    /**
     * Schema WITHOUT 'namespace' (injected by ClusterTool) and without the
     * instance suffix on 'secret' (also applied by ClusterTool, unchanged
     * from today's post-match logic).
     *
     * @return array{secret: string, key: string, template?: string}|null
     */
    public function dbSecretRef(): ?array;
}
