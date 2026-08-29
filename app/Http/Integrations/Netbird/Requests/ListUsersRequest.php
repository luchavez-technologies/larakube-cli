<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Used to resolve the CURRENT user (`is_current`), which is who the stored PAT
 * belongs to and therefore who a replacement PAT must be minted for.
 */
class ListUsersRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'api/users';
    }
}
