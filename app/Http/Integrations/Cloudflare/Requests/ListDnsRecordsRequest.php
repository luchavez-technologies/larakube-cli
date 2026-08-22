<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListDnsRecordsRequest extends Request
{
    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '/example';
    }
}
