<?php

namespace App\Http\Integrations\OpenBao\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/** See DynamicRequest's docblock — this is the no-body counterpart. */
class DynamicNoBodyRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 15;

    public function __construct(
        string $method,
        protected readonly string $path,
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
}
