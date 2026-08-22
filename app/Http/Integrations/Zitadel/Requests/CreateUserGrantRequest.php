<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class CreateUserGrantRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /** @param  list<string>  $roleKeys */
    public function __construct(
        protected readonly string $userId,
        protected readonly string $projectId,
        protected readonly array $roleKeys,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/users/{$this->userId}/grants";
    }

    protected function defaultBody(): array
    {
        return ['projectId' => $this->projectId, 'roleKeys' => $this->roleKeys];
    }
}
