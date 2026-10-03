<?php

namespace App\Http\Integrations\DigitalOcean\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class ListRegionsRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 20;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'v2/regions';
    }

    protected function defaultQuery(): array
    {
        return ['per_page' => 200];
    }
}
