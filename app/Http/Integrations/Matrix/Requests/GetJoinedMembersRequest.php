<?php

namespace App\Http\Integrations\Matrix\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class GetJoinedMembersRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    public function __construct(protected readonly string $roomId) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '_matrix/client/v3/rooms/'.rawurlencode($this->roomId).'/joined_members';
    }
}
