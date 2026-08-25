<?php

namespace App\Http\Integrations\Zitadel\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class ListProjectsRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 60;

    protected int $requestTimeout = 120;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /**
     * Unlike SearchProjectsRequest's name-equality query, this lists EVERY
     * project on the instance — sso:prune's orphan sweep must see projects
     * no longer named like any current tool, which is precisely the point.
     */
    public function resolveEndpoint(): string
    {
        return 'management/v1/projects/_search';
    }

    protected function defaultBody(): array
    {
        return ['queries' => []];
    }
}
