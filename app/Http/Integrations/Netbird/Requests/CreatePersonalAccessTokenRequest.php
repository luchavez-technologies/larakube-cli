<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Mint a new PAT for a user. NetBird caps a PAT's lifetime, so the token
 * vpn:init bootstraps with expires — and when it does, every LaraKube→NetBird
 * call stops working at once. This is what lets vpn:rotate mint a replacement
 * while the current one is still valid.
 */
class CreatePersonalAccessTokenRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $userId,
        protected readonly string $name,
        protected readonly int $expiresInDays,
    ) {}

    public function resolveEndpoint(): string
    {
        return "api/users/{$this->userId}/tokens";
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'expires_in' => $this->expiresInDays,
        ];
    }
}
