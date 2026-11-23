<?php

namespace App\Http\Integrations\Google\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Plugins\HasTimeout;

/** Trade a signed service account JWT for a short-lived access token (POST /token). */
class ExchangeServiceAccountJwtRequest extends Request implements HasBody
{
    use HasFormBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 30;

    protected Method $method = Method::POST;

    public function __construct(protected readonly string $assertion) {}

    public function resolveEndpoint(): string
    {
        return 'token';
    }

    /** @return array<string, string> */
    protected function defaultBody(): array
    {
        return [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->assertion,
        ];
    }
}
