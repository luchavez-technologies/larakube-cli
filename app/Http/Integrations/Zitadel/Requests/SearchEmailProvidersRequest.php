<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class SearchEmailProvidersRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 15;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct()
    {
        // No filter — Zitadel wants a literal {} here, but json_encode([])
        // always produces "[]" regardless of intent (a plain PHP
        // array-vs-object ambiguity, not a Saloon limitation).
        $this->body()->setJsonFlags(JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'admin/v1/email/_search';
    }

    protected function defaultBody(): array
    {
        return [];
    }
}
