<?php

namespace App\Http\Integrations\Cloudflare\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class GetZoneSettingRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::GET;

    public function __construct(
        protected readonly string $zoneId,
        protected readonly string $setting,
    ) {}

    public function resolveEndpoint(): string
    {
        return "client/v4/zones/{$this->zoneId}/settings/{$this->setting}";
    }
}
