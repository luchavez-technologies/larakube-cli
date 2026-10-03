<?php

namespace App\Http\Integrations\DigitalOcean\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Every Droplet size, with its current monthly price and the regions it is offered in. */
class ListSizesRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 20;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'v2/sizes';
    }

    protected function defaultQuery(): array
    {
        return ['per_page' => 200];
    }
}
