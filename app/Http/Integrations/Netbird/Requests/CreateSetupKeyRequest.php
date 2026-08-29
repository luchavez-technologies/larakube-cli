<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class CreateSetupKeyRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $name,
        protected readonly int $expiresIn,
        protected readonly int $usageLimit,
        protected readonly bool $ephemeral = false,
        /** @var list<string> Group ids every peer joining with this key is placed into. */
        protected readonly array $autoGroups = [],
    ) {}

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'api/setup-keys';
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'type' => 'reusable',
            'expires_in' => $this->expiresIn,
            'usage_limit' => $this->usageLimit,
            'ephemeral' => $this->ephemeral,
            // Placing the peer at enrolment is the only way it lands in the right
            // group at all — nothing moves it there afterwards.
            'auto_groups' => $this->autoGroups,
        ];
    }
}
