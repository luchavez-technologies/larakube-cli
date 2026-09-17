<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class DeleteDnsRecordRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::DELETE;

    public function __construct(
        protected readonly string $zoneId,
        protected readonly string $recordId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "client/v4/zones/{$this->zoneId}/dns_records/{$this->recordId}";
    }
}
