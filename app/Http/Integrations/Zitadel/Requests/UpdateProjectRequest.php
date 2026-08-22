<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class UpdateProjectRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::PUT;

    public function __construct(
        protected readonly string $projectId,
        protected readonly string $name,
        protected readonly bool $projectRoleAssertion,
        protected readonly bool $projectRoleCheck,
        protected readonly bool $hasProjectCheck,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/projects/{$this->projectId}";
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'projectRoleAssertion' => $this->projectRoleAssertion,
            'projectRoleCheck' => $this->projectRoleCheck,
            'hasProjectCheck' => $this->hasProjectCheck,
        ];
    }
}
