<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** Remove the split-DNS group entirely — what `vpn:unwire` reaches when it retires the last VPN-only host, so no group is left pointing at nothing. */
class DeleteNameserverGroupRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::DELETE;

    public function __construct(protected readonly string $id) {}

    public function resolveEndpoint(): string
    {
        return 'api/dns/nameservers/'.$this->id;
    }
}
