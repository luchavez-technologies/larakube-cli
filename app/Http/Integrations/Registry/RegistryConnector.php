<?php

namespace App\Http\Integrations\Registry;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;

class RegistryConnector extends Connector
{
    use HasTimeout;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 30;

    public function __construct(
        protected readonly string $host = 'registry-1.docker.io',
    ) {}

    public function resolveBaseUrl(): string
    {
        return "https://{$this->host}";
    }
}
