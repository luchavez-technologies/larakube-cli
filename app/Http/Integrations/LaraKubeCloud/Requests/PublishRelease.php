<?php

namespace App\Http\Integrations\LaraKubeCloud\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class PublishRelease extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected Method $method = Method::POST;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 30;

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{path: string, content: string}>  $files
     */
    public function __construct(
        public readonly string $token,
        public readonly array $manifest,
        public readonly string $signature,
        public readonly string $keyId,
        public readonly array $files,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/releases';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->token}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'manifest' => $this->manifest,
            'signature' => $this->signature,
            'key_id' => $this->keyId,
            'files' => $this->files,
        ];
    }
}
