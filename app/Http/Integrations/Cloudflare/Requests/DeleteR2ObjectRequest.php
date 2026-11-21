<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Delete one object from an R2 bucket (DELETE /accounts/{id}/r2/buckets/{bucket}/objects/{key}). */
class DeleteR2ObjectRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 30;

    protected Method $method = Method::DELETE;

    public function __construct(
        protected readonly string $accountId,
        protected readonly string $bucket,
        protected readonly string $key,
    ) {}

    public function resolveEndpoint(): string
    {
        // The key keeps its slashes, as Cloudflare's own clients send it; each segment is encoded.
        $key = implode('/', array_map('rawurlencode', explode('/', $this->key)));

        return "client/v4/accounts/{$this->accountId}/r2/buckets/{$this->bucket}/objects/{$key}";
    }
}
