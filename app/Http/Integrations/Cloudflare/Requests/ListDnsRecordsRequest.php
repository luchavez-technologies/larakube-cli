<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class ListDnsRecordsRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    public function __construct(
        protected readonly string $zoneId,
        protected readonly ?string $type = null,
        protected readonly ?string $name = null,
        protected readonly int $perPage = 100,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "client/v4/zones/{$this->zoneId}/dns_records";
    }

    protected function defaultQuery(): array
    {
        $query = ['per_page' => $this->perPage];

        if ($this->type !== null) {
            $query['type'] = $this->type;
        }

        if ($this->name !== null) {
            $query['name'] = $this->name;
        }

        return $query;
    }
}
