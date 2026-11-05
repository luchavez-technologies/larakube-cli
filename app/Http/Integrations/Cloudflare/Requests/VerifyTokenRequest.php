<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Validates a Cloudflare API token via GET /client/v4/user/tokens/verify.
 */
class VerifyTokenRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 30;

    protected int $requestTimeout = 60;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'client/v4/user/tokens/verify';
    }
}
