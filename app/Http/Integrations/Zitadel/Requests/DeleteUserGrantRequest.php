<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class DeleteUserGrantRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::DELETE;

    public function __construct(protected readonly string $userId, protected readonly string $grantId) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/users/{$this->userId}/grants/{$this->grantId}";
    }
}
