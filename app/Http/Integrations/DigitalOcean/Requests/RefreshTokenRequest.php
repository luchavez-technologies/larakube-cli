<?php

namespace App\Http\Integrations\DigitalOcean\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Plugins\HasTimeout;

/** Exchanges a refresh token for a new access token, silently, with no browser involved. */
class RefreshTokenRequest extends Request implements HasBody
{
    use HasFormBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 30;

    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $clientId,
        protected readonly string $refreshToken,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/token';
    }

    protected function defaultBody(): array
    {
        return [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'refresh_token' => $this->refreshToken,
        ];
    }
}
