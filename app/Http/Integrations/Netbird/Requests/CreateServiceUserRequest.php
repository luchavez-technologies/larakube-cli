<?php

namespace App\Http\Integrations\Netbird\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Create a NetBird service user — a machine identity that owns tokens but
 * cannot log in.
 *
 * vpn:init used to hang the CLI's PAT off the human owner created by
 * /api/setup. That works until the human does: delete them, or drop their role,
 * and every LaraKube→NetBird call in every environment fails at once, with no
 * way back in because the PAT that could mint a replacement died with them.
 * A service user has no such lifecycle.
 */
class CreateServiceUserRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    protected Method $method = Method::POST;

    /** @param  list<string>  $autoGroups */
    public function __construct(
        protected readonly string $name,
        protected readonly string $role = 'admin',
        protected readonly array $autoGroups = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return 'api/users';
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            // Required by the API even for a service user, which never receives
            // mail — a non-routable address makes that explicit.
            'email' => '',
            'role' => $this->role,
            'auto_groups' => $this->autoGroups,
            'is_service_user' => true,
        ];
    }
}
