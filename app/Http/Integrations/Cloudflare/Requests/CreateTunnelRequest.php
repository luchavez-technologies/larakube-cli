<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/** A remotely managed tunnel: its routes live in Cloudflare, so the connector needs only its token. */
class CreateTunnelRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::POST;

    public function __construct(protected readonly string $accountId, protected readonly string $name) {}

    public function resolveEndpoint(): string
    {
        return "client/v4/accounts/{$this->accountId}/cfd_tunnel";
    }

    protected function defaultBody(): array
    {
        return ['name' => $this->name, 'config_src' => 'cloudflare'];
    }
}
