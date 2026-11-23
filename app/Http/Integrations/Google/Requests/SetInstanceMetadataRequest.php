<?php

namespace App\Http\Integrations\Google\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/** Replace an instance's metadata. Google rejects it with 412 if the fingerprint is stale. */
class SetInstanceMetadataRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 30;

    protected Method $method = Method::POST;

    /** @param list<array{key: string, value: string}> $items */
    public function __construct(
        protected readonly string $project,
        protected readonly string $zone,
        protected readonly string $instance,
        protected readonly string $fingerprint,
        protected readonly array $items,
    ) {}

    public function resolveEndpoint(): string
    {
        return "projects/{$this->project}/zones/{$this->zone}/instances/{$this->instance}/setMetadata";
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return ['fingerprint' => $this->fingerprint, 'items' => $this->items];
    }
}
