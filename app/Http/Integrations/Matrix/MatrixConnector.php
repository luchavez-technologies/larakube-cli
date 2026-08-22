<?php

namespace App\Http\Integrations\Matrix;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Host is per-instance (each chat install has its own Synapse), so it's a
 * constructor argument rather than a fixed resolveBaseUrl() like
 * CloudflareConnector — there is no single "the" Matrix host. $token is
 * optional: the nonce fetch and the shared-secret register/login calls in
 * InteractsWithMatrixApi::matrixAdminToken() are unauthenticated by Matrix's
 * own design (that's the bootstrap step that PRODUCES the token everything
 * else authenticates with).
 */
class MatrixConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly string $host, protected readonly ?string $token = null) {}

    public function resolveBaseUrl(): string
    {
        return "https://{$this->host}";
    }

    protected function defaultAuth(): ?TokenAuthenticator
    {
        return $this->token !== null ? new TokenAuthenticator($this->token) : null;
    }
}
