<?php

namespace App\Http\Integrations\Matrix\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class GetRoomByAliasRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    public function __construct(protected readonly string $alias) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '_matrix/client/v3/directory/room/'.rawurlencode($this->alias);
    }
}
