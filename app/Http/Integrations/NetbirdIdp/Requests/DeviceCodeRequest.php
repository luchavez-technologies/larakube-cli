<?php

namespace App\Http\Integrations\NetbirdIdp\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Plugins\HasTimeout;

/** Start the device-code grant; returns a user_code and a URL to visit. */
class DeviceCodeRequest extends Request implements HasBody
{
    use HasFormBody, HasTimeout;

    protected int $connectTimeout = 30;

    protected int $requestTimeout = 60;

    protected Method $method = Method::POST;

    public function __construct(protected readonly string $clientId) {}

    public function resolveEndpoint(): string
    {
        return '/device/code';
    }

    protected function defaultBody(): array
    {
        // `email` is the load-bearing scope: NetBird derives the account's
        // domain from that claim, and an account without one cannot be shared.
        return ['client_id' => $this->clientId, 'scope' => 'openid profile email'];
    }
}
