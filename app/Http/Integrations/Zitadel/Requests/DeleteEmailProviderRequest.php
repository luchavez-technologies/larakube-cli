<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class DeleteEmailProviderRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 10;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::DELETE;

    public function __construct(
        protected readonly string $providerId,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "admin/v1/email/{$this->providerId}";
    }
}
