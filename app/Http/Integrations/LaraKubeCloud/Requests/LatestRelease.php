<?php

namespace App\Http\Integrations\LaraKubeCloud\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class LatestRelease extends Request
{
    use HasTimeout;

    protected Method $method = Method::GET;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 30;

    /**
     * @param  list<int>  $contracts
     */
    public function __construct(
        public readonly string $app,
        public readonly string $engine = 'default',
        public readonly array $contracts = [1],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/apps/{$this->app}/releases/latest";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'engine' => $this->engine,
            'contracts' => array_values($this->contracts),
        ];
    }
}
