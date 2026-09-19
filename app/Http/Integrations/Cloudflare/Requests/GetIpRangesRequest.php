<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Cloudflare's published edge IP ranges. Public: no token needed. */
class GetIpRangesRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 30;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'client/v4/ips';
    }
}
