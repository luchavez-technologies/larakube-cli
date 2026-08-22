<?php

namespace App\Http\Integrations\Zitadel\Requests;

use App\Http\Integrations\Zitadel\Concerns\HasOrgHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class SearchOrgDomainsRequest extends Request implements HasBody
{
    use HasJsonBody, HasOrgHeader, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /**
     * Zitadel wants a literal JSON object body ({}), not an array ([]), even
     * with no filters — json_encode([]) always produces "[]" regardless of
     * intent, so this is forced explicitly rather than left to the default
     * encoding. Set here, on the Request's own body(), before Saloon clones
     * it into the pipeline — see zitadelEnsureRbacAction()'s own docblock
     * for the confirmed-live version of this same Zitadel quirk.
     */
    public function __construct(protected readonly ?string $orgId = null)
    {
        $this->body()->setJsonFlags(JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'management/v1/orgs/me/domains/_search';
    }

    protected function defaultBody(): array
    {
        return [];
    }
}
