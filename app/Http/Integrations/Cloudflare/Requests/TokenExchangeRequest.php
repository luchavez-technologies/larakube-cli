<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Plugins\HasTimeout;

/** Exchanges an authorization code for tokens at POST /oauth2/token — PKCE only, no client_secret. */
class TokenExchangeRequest extends Request implements HasBody
{
    use HasFormBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 30;

    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $clientId,
        protected readonly string $code,
        protected readonly string $codeVerifier,
        protected readonly string $redirectUri,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/token';
    }

    protected function defaultBody(): array
    {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'code' => $this->code,
            'code_verifier' => $this->codeVerifier,
            'redirect_uri' => $this->redirectUri,
        ];
    }
}
