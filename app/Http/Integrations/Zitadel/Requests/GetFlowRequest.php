<?php

namespace App\Http\Integrations\Zitadel\Requests;

use App\Http\Integrations\Zitadel\Concerns\HasOrgHeader;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class GetFlowRequest extends Request
{
    use HasOrgHeader, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    public function __construct(protected readonly int $flowType, protected readonly ?string $orgId = null) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/flows/{$this->flowType}";
    }
}
