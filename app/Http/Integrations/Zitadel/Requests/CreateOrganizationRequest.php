<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class CreateOrganizationRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(protected readonly string $name) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'v2/organizations';
    }

    protected function defaultBody(): array
    {
        return ['name' => $this->name];
    }
}
