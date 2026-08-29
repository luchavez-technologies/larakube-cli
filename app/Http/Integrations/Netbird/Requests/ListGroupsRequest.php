<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Every group in the account. Group names are unique per account in practice, but the API does not enforce it — so callers must look before creating. */
class ListGroupsRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'api/groups';
    }
}
