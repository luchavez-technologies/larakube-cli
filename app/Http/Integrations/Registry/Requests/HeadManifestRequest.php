<?php

namespace App\Http\Integrations\Registry\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

class HeadManifestRequest extends Request
{
    use HasTimeout;

    public const ACCEPT_HEADER = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

    protected Method $method = Method::HEAD;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 30;

    public function __construct(
        public readonly string $host,
        public readonly string $repo,
        public readonly string $tag,
        public readonly ?string $token = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/v2/{$this->repo}/manifests/{$this->tag}";
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        $headers = [
            'Accept' => self::ACCEPT_HEADER,
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        return $headers;
    }
}
