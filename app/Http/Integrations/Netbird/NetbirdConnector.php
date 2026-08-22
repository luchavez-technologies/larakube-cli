<?php

namespace App\Http\Integrations\Netbird;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Host is per-instance (each cluster's own NetBird management), so it's a
 * constructor argument, same as MatrixConnector. $pat is optional: the
 * one-time owner-bootstrap call (POST /api/setup, VpnInitCommand's
 * bootstrapVpnAuth()) happens before any PAT exists — it's what PRODUCES
 * the token every other call authenticates with.
 *
 * NetBird's REST API wants `Authorization: Token {pat}`, not `Bearer` —
 * TokenAuthenticator's own $prefix argument covers this directly, no custom
 * Authenticator class needed.
 */
class NetbirdConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly string $host, protected readonly ?string $pat = null) {}

    public function resolveBaseUrl(): string
    {
        return "https://{$this->host}";
    }

    protected function defaultAuth(): ?TokenAuthenticator
    {
        return $this->pat !== null ? new TokenAuthenticator($this->pat, 'Token') : null;
    }
}
