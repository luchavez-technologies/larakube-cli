<?php

namespace App\Http\Integrations\Google;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class GoogleComputeConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected readonly string $token) {}

    public function resolveBaseUrl(): string
    {
        return 'https://compute.googleapis.com/compute/v1';
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }
}
