<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class CreateR2BucketRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $accountId,
        protected readonly string $bucket,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "client/v4/accounts/{$this->accountId}/r2/buckets";
    }

    protected function defaultBody(): array
    {
        return ['name' => $this->bucket];
    }
}
