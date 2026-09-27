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
     * 'kind' names which of the component's Secrets this is, for a tool on
     * canonical naming. It defaults to CREDENTIALS because most tools keep
     * the database password in the same Secret as everything else they
     * generate; a vendor that deliberately separates the two (VpnTool) says
     * STORE, or the two names collapse into one and a rotation writes over
     * credentials it has nothing to do with.
     *
     * @return array{secret: string, key: string, template?: string, kind?: \App\Enums\SecretKind}|null
     */
    public function dbSecretRef(): ?array;
}
