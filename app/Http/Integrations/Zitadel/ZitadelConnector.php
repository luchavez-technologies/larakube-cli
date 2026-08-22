<?php

namespace App\Http\Integrations\Zitadel;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Host is per-instance (each cluster's own Zitadel), so it's a constructor
 * argument, same shape as MatrixConnector/NetbirdConnector. Unlike those two,
 * every Zitadel call carries the machine PAT — there's no unauthenticated
 * bootstrap step here (the PAT itself comes from sso:init's own kubectl-based
 * capture, not from a Zitadel API call).
 */
class ZitadelConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly string $host, protected readonly string $pat) {}

    public function resolveBaseUrl(): string
    {
        return "https://{$this->host}";
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->pat);
    }
}
