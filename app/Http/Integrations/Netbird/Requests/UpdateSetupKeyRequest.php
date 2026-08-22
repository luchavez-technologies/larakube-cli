<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class UpdateSetupKeyRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::PUT;

    /**
     * NetBird's PUT requires the FULL object back (a partial {"revoked":true}
     * 422s with "setup key autogroups field is invalid" — empirically
     * confirmed, undocumented) — $key is one entry from ListSetupKeysRequest,
     * $expiresIn is recomputed by the caller since the list response never
     * carries the original relative value, only the absolute `expires`.
     *
     * @param  array<string, mixed>  $key
     */
    public function __construct(protected readonly array $key, protected readonly int $expiresIn) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'api/setup-keys/'.($this->key['id'] ?? '');
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->key['name'] ?? '',
            'type' => $this->key['type'] ?? 'reusable',
            'expires_in' => $this->expiresIn,
            'usage_limit' => $this->key['usage_limit'] ?? 0,
            'revoked' => true,
            'auto_groups' => $this->key['auto_groups'] ?? [],
            'ephemeral' => $this->key['ephemeral'] ?? false,
            'allow_extra_dns_labels' => $this->key['allow_extra_dns_labels'] ?? false,
        ];
    }
}
