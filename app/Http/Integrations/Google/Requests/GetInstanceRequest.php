<?php

namespace App\Http\Integrations\Google\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class GetInstanceRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 30;

    protected Method $method = Method::GET;

    public function __construct(
        protected readonly string $project,
        protected readonly string $zone,
        protected readonly string $instance,
    ) {}

    public function resolveEndpoint(): string
    {
        return "projects/{$this->project}/zones/{$this->zone}/instances/{$this->instance}";
    }
}
