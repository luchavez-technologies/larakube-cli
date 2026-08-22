<?php

namespace App\Http\Integrations\Matrix\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class LoginRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(protected readonly string $username, protected readonly string $password) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '_matrix/client/v3/login';
    }

    protected function defaultBody(): array
    {
        return [
            'type' => 'm.login.password',
            'identifier' => ['type' => 'm.id.user', 'user' => $this->username],
            'password' => $this->password,
        ];
    }
}
