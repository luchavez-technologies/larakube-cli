<?php

namespace App\Http\Integrations\OpenBao\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Unauthenticated liveness probe — no token needed, works even against a
 * sealed vault. The prototype request for proving the port-forward +
 * SaloonPHP combination works before converting openBaoApi()'s other,
 * authenticated call sites.
 */
class SysHealthRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 15;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'v1/sys/health';
    }
}
