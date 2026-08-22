<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Unfiltered `GET /zones` — every zone the token can see, not just one by name. */
class ListZonesRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    public function __construct(protected readonly int $page = 1, protected readonly int $perPage = 50) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'client/v4/zones';
    }

    protected function defaultQuery(): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage];
    }
}
