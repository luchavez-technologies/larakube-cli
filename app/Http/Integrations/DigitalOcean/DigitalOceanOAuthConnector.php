<?php

namespace App\Http\Integrations\DigitalOcean;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * DigitalOcean's OAuth authorize/token endpoints live on a different host
 * (cloud.digitalocean.com) than the authenticated API (api.digitalocean.com,
 * see DigitalOceanConnector) and take no bearer auth of their own — the
 * token/refresh requests authenticate via the PKCE code_verifier/refresh_token
 * in the body instead, never a client_secret (public native-app client).
 */
class DigitalOceanOAuthConnector extends Connector
{
    use AcceptsJson;

    public function resolveBaseUrl(): string
    {
        return 'https://cloud.digitalocean.com/v1/oauth';
    }
}
