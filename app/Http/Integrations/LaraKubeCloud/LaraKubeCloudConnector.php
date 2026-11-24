<?php

namespace App\Http\Integrations\LaraKubeCloud;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

class LaraKubeCloudConnector extends Connector
{
    use AcceptsJson, HasTimeout;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 30;

    public function __construct(
        protected readonly ?string $baseUrl = null,
        protected readonly ?string $caBundle = null,
    ) {}

    public function resolveBaseUrl(): string
    {
        $url = $this->baseUrl ?? (string) config('templates.cloud_url', 'https://cloud.larakube.app');

        return rtrim($url, '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        $caBundle = $this->caBundle ?? config('templates.ca_bundle');

        if (is_string($caBundle) && $caBundle !== '') {
            return ['verify' => $caBundle];
        }

        return [];
    }
}
