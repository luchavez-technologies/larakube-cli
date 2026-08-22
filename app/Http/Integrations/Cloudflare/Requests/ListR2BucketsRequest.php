<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListR2BucketsRequest extends Request
{
    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    public function __construct(protected readonly string $accountId) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "client/v4/accounts/$this->accountId/r2/buckets";
    }
}
