<?php

namespace App\Http\Integrations\NetbirdIdp\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Exchange a device code for tokens. Returns 400 with
 * `error: authorization_pending` until the person finishes signing in — that is
 * the normal polling response, not a failure.
 */
class DeviceTokenRequest extends Request implements HasBody
{
    use HasFormBody, HasTimeout;

    protected int $connectTimeout = 30;

    protected int $requestTimeout = 60;

    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $clientId,
        protected readonly string $deviceCode,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/token';
    }

    protected function defaultBody(): array
    {
        return [
            'client_id' => $this->clientId,
            'device_code' => $this->deviceCode,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ];
    }
}
