<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class DeactivateUserRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(protected readonly string $userId) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "v2/users/{$this->userId}/deactivate";
    }
}
