<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Unfiltered + local match (see zitadelFindProjectGrant()'s own comment) —
 * this project's grant list is small (one per partner org), and a
 * server-side filter's exact field name for "by granted org" isn't
 * documented alongside the other _search endpoints in this trait.
 */
class SearchProjectGrantsRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(protected readonly string $projectId) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/projects/{$this->projectId}/grants/_search";
    }

    protected function defaultBody(): array
    {
        return ['queries' => []];
    }
}
