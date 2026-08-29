<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Delete an account. A token can only ever delete its OWN account, which makes
 * this useful for exactly one job: retiring the domain-less account /api/setup
 * creates, so the next login can create one that SSO users can actually join.
 */
class DeleteAccountRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::DELETE;

    public function __construct(protected readonly string $accountId) {}

    public function resolveEndpoint(): string
    {
        return "api/accounts/{$this->accountId}";
    }
}
