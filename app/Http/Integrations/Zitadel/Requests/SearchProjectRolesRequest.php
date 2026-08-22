<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * $roleKey filters by exact key (zitadelEnsureProjectRole()'s existence
 * check); omitted, every role on the project is returned
 * (zitadelListProjectRoleKeys()).
 */
class SearchProjectRolesRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(protected readonly string $projectId, protected readonly ?string $roleKey = null) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/projects/{$this->projectId}/roles/_search";
    }

    protected function defaultBody(): array
    {
        return [
            'queries' => $this->roleKey !== null
                ? [['keyQuery' => ['key' => $this->roleKey]]]
                : [],
        ];
    }
}
