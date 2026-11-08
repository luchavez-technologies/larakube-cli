<?php

namespace App\Http\Integrations\DigitalOcean\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Identifies which DigitalOcean account a token belongs to — `uuid` is the
 * stable key ADR 0029 uses to dedupe/update a stored cloud account instead of
 * asking the user to name it; `email` is what gets shown as that entry's name.
 */
class GetAccountRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 20;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'v2/account';
    }
}
