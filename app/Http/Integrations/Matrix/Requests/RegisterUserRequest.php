<?php

namespace App\Http\Integrations\Matrix\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class RegisterUserRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $nonce,
        protected readonly string $username,
        protected readonly string $password,
        protected readonly string $mac,
        protected readonly bool $admin = true,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '_synapse/admin/v1/register';
    }

    protected function defaultBody(): array
    {
        return [
            'nonce' => $this->nonce,
            'username' => $this->username,
            'password' => $this->password,
            'admin' => $this->admin,
            'mac' => $this->mac,
        ];
    }
}
