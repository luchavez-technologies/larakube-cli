<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class CreateUserRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $email,
        protected readonly string $givenName,
        protected readonly string $familyName,
        protected readonly string $displayName,
        protected readonly string $password,
        protected readonly ?string $orgId = null,
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'v2/users/human';
    }

    protected function defaultBody(): array
    {
        $body = [
            'username' => $this->email,
            'profile' => [
                'givenName' => $this->givenName,
                'familyName' => $this->familyName,
                'displayName' => $this->displayName,
            ],
            'email' => [
                'email' => $this->email,
                'isVerified' => true,
            ],
            'password' => [
                'password' => $this->password,
                'changeRequired' => false,
            ],
        ];

        if ($this->orgId !== null) {
            $body['organization'] = ['orgId' => $this->orgId];
        }

        return $body;
    }
}
