<?php

namespace App\Http\Integrations\NetbirdIdp;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * NetBird's EMBEDDED identity provider (Dex), served under /oauth2 on the same
 * host as management. Separate from NetbirdConnector because it is a different
 * API with different auth: OAuth endpoints, no PAT.
 *
 * Used for the device-code grant, which is how the CLI obtains a real user's
 * access token without a browser of its own.
 */
class NetbirdIdpConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly string $host) {}

    public function resolveBaseUrl(): string
    {
        return "https://{$this->host}/oauth2";
    }
}
