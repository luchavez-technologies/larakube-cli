<?php

namespace App\Http\Integrations\Matrix\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class CreateRoomRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /** @param  list<string>  $inviteUserIds */
    public function __construct(
        protected readonly string $name,
        protected readonly string $aliasLocalPart,
        protected readonly ?string $topic,
        protected readonly array $inviteUserIds,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '_matrix/client/v3/createRoom';
    }

    protected function defaultBody(): array
    {
        $body = [
            'name' => $this->name,
            'room_alias_name' => $this->aliasLocalPart,
            'preset' => 'private_chat',
            'invite' => array_values($this->inviteUserIds),
        ];

        if ($this->topic !== null && $this->topic !== '') {
            $body['topic'] = $this->topic;
        }

        return $body;
    }
}
