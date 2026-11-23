<?php

namespace App\Http\Integrations\Google\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Block until a zone operation is done, or Google's own wait limit of about two minutes passes. */
class WaitZoneOperationRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 150;

    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $project,
        protected readonly string $zone,
        protected readonly string $operation,
    ) {}

    public function resolveEndpoint(): string
    {
        return "projects/{$this->project}/zones/{$this->zone}/operations/{$this->operation}/wait";
    }
}
