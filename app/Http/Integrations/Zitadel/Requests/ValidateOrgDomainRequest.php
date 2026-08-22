<?php

namespace App\Http\Integrations\Zitadel\Requests;

use App\Http\Integrations\Zitadel\Concerns\HasOrgHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class ValidateOrgDomainRequest extends Request implements HasBody
{
    use HasJsonBody, HasOrgHeader, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /** See SearchOrgDomainsRequest's own comment — Zitadel wants a literal {} here too. */
    public function __construct(protected readonly string $domain, protected readonly ?string $orgId = null)
    {
        $this->body()->setJsonFlags(JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return "management/v1/orgs/me/domains/{$this->domain}/validation";
    }

    protected function defaultBody(): array
    {
        return [];
    }
}
