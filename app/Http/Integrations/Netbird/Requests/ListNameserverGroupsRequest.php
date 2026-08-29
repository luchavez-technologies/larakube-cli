<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Every nameserver group in the account — the list `vpn:init` reconciles against so a re-run updates its group instead of stacking duplicates. */
class ListNameserverGroupsRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'api/dns/nameservers';
    }
}
