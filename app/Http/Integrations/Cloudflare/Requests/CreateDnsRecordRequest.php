<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class CreateDnsRecordRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $zoneId,
        protected readonly string $type,
        protected readonly string $name,
        protected readonly string $content,
        protected readonly int $ttl,
        protected readonly bool $proxied = false,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "client/v4/zones/{$this->zoneId}/dns_records";
    }

    protected function defaultBody(): array
    {
        return array_filter([
            'type' => $this->type,
            'name' => $this->name,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'proxied' => $this->proxied ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
