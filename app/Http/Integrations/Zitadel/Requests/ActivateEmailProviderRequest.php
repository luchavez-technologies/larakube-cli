<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class ActivateEmailProviderRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 15;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $providerId,
    ) {
        // Zitadel wants a literal {} body here — see
        // SearchEmailProvidersRequest for why JSON_FORCE_OBJECT is needed.
        $this->body()->setJsonFlags(JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "admin/v1/email/{$this->providerId}/_activate";
    }

    protected function defaultBody(): array
    {
        return [];
    }
}
