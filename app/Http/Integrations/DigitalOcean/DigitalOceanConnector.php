<?php

namespace App\Http\Integrations\DigitalOcean;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class DigitalOceanConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly string $token) {}

    public function resolveBaseUrl(): string
    {
        return 'https://api.digitalocean.com';
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }
}
