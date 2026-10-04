<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** The live tunnels of an account that carry this name. */
class ListTunnelsRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::GET;

    public function __construct(protected readonly string $accountId, protected readonly string $name) {}

    public function resolveEndpoint(): string
    {
        return "client/v4/accounts/{$this->accountId}/cfd_tunnel";
    }

    protected function defaultQuery(): array
    {
        return ['name' => $this->name, 'is_deleted' => 'false'];
    }
}
