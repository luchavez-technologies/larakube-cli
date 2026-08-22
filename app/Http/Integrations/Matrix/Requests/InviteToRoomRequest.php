<?php

namespace App\Http\Integrations\Matrix\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class InviteToRoomRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(protected readonly string $roomId, protected readonly string $userId) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '_matrix/client/v3/rooms/'.rawurlencode($this->roomId).'/invite';
    }

    protected function defaultBody(): array
    {
        return ['user_id' => $this->userId];
    }
}
