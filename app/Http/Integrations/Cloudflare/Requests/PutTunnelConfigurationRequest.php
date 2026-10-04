<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/** Replaces the whole route list of a tunnel; the last rule must be the catch-all. */
class PutTunnelConfigurationRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::PUT;

    public function __construct(protected readonly string $accountId, protected readonly string $tunnelId, protected readonly array $ingress) {}

    public function resolveEndpoint(): string
    {
        return "client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations";
    }

    protected function defaultBody(): array
    {
        return ['config' => ['ingress' => $this->ingress]];
    }
}
