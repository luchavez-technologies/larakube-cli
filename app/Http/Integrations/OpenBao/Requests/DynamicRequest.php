<?php

namespace App\Http\Integrations\OpenBao\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * openBaoApi()'s callers hit dozens of distinct, often dynamically-built
 * paths (role names, environment slugs, secret keys interpolated in) —
 * turning every one into its own named Request class would multiply classes
 * without adding real typed value, since they all share one wire shape. This
 * generic request carries the method/path/body openBaoApi() already
 * receives from its caller, same as the OpenBao API itself is: a single
 * verb+path+JSON-body protocol, not a set of RPC-style named operations.
 *
 * Used only when a body is present — see DynamicNoBodyRequest for GET/DELETE
 * (or any call) with none, since a Saloon Request either always attaches a
 * body (HasBody) or never does; there's no per-call opt-out.
 */
class DynamicRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 15;

    public function __construct(
        string $method,
        protected readonly string $path,
        protected readonly array $data,
    ) {
        $this->method = Method::from(strtoupper($method));
    }

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return $this->path;
    }

    protected function defaultBody(): array
    {
        return $this->data;
    }
}
