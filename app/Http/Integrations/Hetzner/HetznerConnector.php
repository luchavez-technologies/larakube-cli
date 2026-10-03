<?php

namespace App\Http\Integrations\Hetzner;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class HetznerConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly string $token) {}

    public function resolveBaseUrl(): string
    {
        return 'https://api.hetzner.cloud';
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }
}
