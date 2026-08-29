<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Read a user's PATs so vpn:show can surface how long the stored one has left.
 * The token value itself is redacted on every read — only metadata comes back,
 * which is all the expiry warning needs.
 */
class ListPersonalAccessTokensRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 30;

    protected int $requestTimeout = 60;

    protected Method $method = Method::GET;

    public function __construct(protected readonly string $userId) {}

    public function resolveEndpoint(): string
    {
        return "api/users/{$this->userId}/tokens";
    }
}
