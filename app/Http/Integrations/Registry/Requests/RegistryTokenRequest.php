<?php

namespace App\Http\Integrations\Registry\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

class RegistryTokenRequest extends Request
{
    use AcceptsJson, HasTimeout;

    protected Method $method = Method::GET;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 30;

    public function __construct(
        public readonly string $realm,
        public readonly ?string $service = null,
        public readonly ?string $scope = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->realm;
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        $query = [];

        if ($this->service !== null && $this->service !== '') {
            $query['service'] = $this->service;
        }

        if ($this->scope !== null && $this->scope !== '') {
            $query['scope'] = $this->scope;
        }

        return $query;
    }
}
