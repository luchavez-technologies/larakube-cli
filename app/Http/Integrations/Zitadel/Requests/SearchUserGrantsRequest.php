<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class SearchUserGrantsRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(protected readonly string $userId, protected readonly string $projectId) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'management/v1/users/grants/_search';
    }

    protected function defaultBody(): array
    {
        return [
            'queries' => [
                ['userIdQuery' => ['userId' => $this->userId]],
                ['projectIdQuery' => ['projectId' => $this->projectId]],
            ],
        ];
    }
}
