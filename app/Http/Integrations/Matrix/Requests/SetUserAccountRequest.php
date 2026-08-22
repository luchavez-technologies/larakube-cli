<?php

namespace App\Http\Integrations\Matrix\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class SetUserAccountRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::PUT;

    public function __construct(
        protected readonly string $userId,
        protected readonly ?string $password,
        protected readonly ?string $displayName,
        protected readonly bool $admin,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return '_synapse/admin/v2/users/'.rawurlencode($this->userId);
    }

    protected function defaultBody(): array
    {
        $body = ['admin' => $this->admin];

        if ($this->password !== null && $this->password !== '') {
            $body['password'] = $this->password;
        }
        if ($this->displayName !== null && $this->displayName !== '') {
            $body['displayname'] = $this->displayName;
        }

        return $body;
    }
}
