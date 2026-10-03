<?php

namespace App\Http\Integrations\Hetzner\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Every server type with its monthly price per location. */
class ListServerTypesRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 20;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'v1/server_types';
    }

    protected function defaultQuery(): array
    {
        return ['per_page' => 50];
    }
}
