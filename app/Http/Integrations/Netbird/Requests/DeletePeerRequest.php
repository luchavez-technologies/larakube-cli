<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Remove a peer. Used only to retire gateway peers left behind by a previous rollout — each one enrols fresh and orphans its predecessor. */
class DeletePeerRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::DELETE;

    public function __construct(protected readonly string $id) {}

    public function resolveEndpoint(): string
    {
        return 'api/peers/'.$this->id;
    }
}
