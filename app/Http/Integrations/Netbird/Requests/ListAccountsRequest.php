<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Returns ONLY the account the calling token belongs to — never the others on
 * the same management server (verified live 2026-08-28: 1 of 4). Useful for one
 * thing: reading that account's `domain`, which decides whether SSO logins can
 * join it. `domain` and `domain_category` are read-only across the whole API.
 */
class ListAccountsRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'api/accounts';
    }
}
