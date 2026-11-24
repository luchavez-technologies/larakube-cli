<?php

namespace App\Http\Integrations\LaraKubeCloud\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class AppReleases extends Request
{
    use HasTimeout;

    protected Method $method = Method::GET;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 30;

    public function __construct(
        public readonly string $app,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/apps/{$this->app}/releases";
    }
}
