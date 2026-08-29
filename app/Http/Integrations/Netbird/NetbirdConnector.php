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

    public function __construct(
        protected readonly string $host,
        protected readonly ?string $pat = null,
        /**
         * An IdP access token, used INSTEAD of $pat. NetBird accepts either, but
         * only a JWT carries the email claim it derives an account's domain
         * from — so this is the one credential that can bring a usable account
         * into existence. A PAT can never do it: it belongs to an account that
         * must already exist.
         */
        protected readonly ?string $bearer = null,
    ) {}

    public function resolveBaseUrl(): string
    {
        return "https://{$this->host}";
    }

    protected function defaultAuth(): ?TokenAuthenticator
    {
        if ($this->bearer !== null) {
            return new TokenAuthenticator($this->bearer, 'Bearer');
        }

        return $this->pat !== null ? new TokenAuthenticator($this->pat, 'Token') : null;
    }
}
