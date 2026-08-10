<?php

namespace App\Contracts;

/**
 * OIDC-consumer wiring schema for a vendor that supports logging in via an
 * external identity provider. sso:wire fills the schema from the Zitadel
 * app it registers.
 */
interface HasOidcWiring
{
    /**
     * Schema WITHOUT 'namespace' and WITHOUT 'also_patch' — both are
     * category-level (namespace(), alsoPatchDeployments()), merged in by
     * ClusterTool after dispatch.
     *
     * @return array{deployment: string, secret: string, static?: array<string, string>, sso_only_vars?: array<string, string>, vars: array<string, string>, redirect_path: string, public_client?: bool}|null
     */
    public function oidcEnv(?string $instance = null): ?array;
}
