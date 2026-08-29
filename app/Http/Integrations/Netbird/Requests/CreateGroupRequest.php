<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Create a NetBird group.
 *
 * Groups are the unit that policies, DNS distribution and Network access are
 * all scoped by, so nothing can be restricted per-app or per-environment until
 * they exist. Peers are not added here: they arrive via a setup key's
 * `auto_groups`, which places a device in the right group at enrolment rather
 * than leaving it in `All` for someone to move later.
 */
class CreateGroupRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::POST;

    public function __construct(
        protected readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return 'api/groups';
    }

    protected function defaultBody(): array
    {
        return ['name' => $this->name, 'peers' => [], 'resources' => []];
    }
}
