<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class UpdateSmtpProviderRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 15;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::PUT;

    public function __construct(
        protected readonly string $providerId,
        protected readonly string $smtpHost,
        protected readonly string $user,
        protected readonly string $password,
        protected readonly string $senderAddress,
        protected readonly string $senderName,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "admin/v1/email/smtp/{$this->providerId}";
    }

    protected function defaultBody(): array
    {
        return [
            'host' => $this->smtpHost,
            'tls' => true,
            'user' => $this->user,
            'password' => $this->password,
            'senderAddress' => $this->senderAddress,
            'senderName' => $this->senderName,
        ];
    }
}
