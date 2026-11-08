<?php

namespace App\Http\Integrations\Cloudflare;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Cloudflare's self-managed-OAuth endpoints (launched 2026-06-03) live under
 * dash.cloudflare.com/oauth2, a different host than the authenticated API
 * (api.cloudflare.com, see CloudflareConnector). Authorization Code + PKCE
 * with token_endpoint_auth_method: none — no client_secret, same as
 * DigitalOceanOAuthConnector.
 */
class CloudflareOAuthConnector extends Connector
{
    use AcceptsJson;

    public function resolveBaseUrl(): string
    {
        return 'https://dash.cloudflare.com/oauth2';
    }
}
