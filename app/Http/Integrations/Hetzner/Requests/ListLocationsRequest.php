<?php

namespace App\Http\Integrations\Hetzner\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class ListLocationsRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 20;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'v1/locations';
    }
}
