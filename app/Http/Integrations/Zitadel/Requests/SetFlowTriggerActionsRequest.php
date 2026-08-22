<?php

namespace App\Http\Integrations\Zitadel\Requests;

use App\Http\Integrations\Zitadel\Concerns\HasOrgHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class SetFlowTriggerActionsRequest extends Request implements HasBody
{
    use HasJsonBody, HasOrgHeader, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /** @param  list<string>  $actionIds */
    public function __construct(
        protected readonly int $flowType,
        protected readonly int $triggerType,
        protected readonly array $actionIds,
        protected readonly ?string $orgId = null,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/flows/{$this->flowType}/trigger/{$this->triggerType}";
    }

    protected function defaultBody(): array
    {
        return ['actionIds' => $this->actionIds];
    }
}
