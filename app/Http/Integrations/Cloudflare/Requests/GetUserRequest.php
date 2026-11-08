<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Identifies which Cloudflare user a token belongs to — GET /client/v4/user,
 * wrapped the same way every Cloudflare API response is (`result`/`success`).
 * `result.id` is the stable key ADR 0029 uses to dedupe/update a stored cloud
 * account instead of asking the user to name it; `result.email` is what gets
 * shown as that entry's name.
 */
class GetUserRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 20;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'client/v4/user';
    }
}
