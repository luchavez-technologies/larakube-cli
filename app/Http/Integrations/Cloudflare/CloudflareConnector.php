<?php

namespace App\Http\Integrations\Cloudflare;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class CloudflareConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly string $token) {}

    /**
     * The Base URL of the API
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.cloudflare.com';
    }

    protected function defaultAuth(): ?TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }
}
