<?php

namespace App\Http\Integrations\Zitadel\Requests;

use App\Http\Integrations\Zitadel\Concerns\HasOrgHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class UpdateActionRequest extends Request implements HasBody
{
    use HasJsonBody, HasOrgHeader, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::PUT;

    public function __construct(
        protected readonly string $actionId,
        protected readonly string $name,
        protected readonly string $script,
        protected readonly ?string $orgId = null,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/actions/{$this->actionId}";
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'script' => $this->script,
            'fieldMask' => ['paths' => ['name', 'script']],
        ];
    }
}
