<?php

namespace App\Support\Templates;

use App\Http\Integrations\Registry\RegistryConnector;
use App\Http\Integrations\Registry\Requests\HeadManifestRequest;
use App\Http\Integrations\Registry\Requests\RegistryTokenRequest;
use RuntimeException;

class ImageDigestResolver
{
    /**
     * Allowed container registries mapped to their OCI v2 registry API hosts.
     *
     * @var array<string, string>
     */
    public const ALLOWED_REGISTRIES = [
        'docker.io' => 'registry-1.docker.io',
        'ghcr.io' => 'ghcr.io',
        'quay.io' => 'quay.io',
        'registry.k8s.io' => 'registry.k8s.io',
        'gcr.io' => 'gcr.io',
        'oci.external-secrets.io' => 'oci.external-secrets.io',
        'docker.elastic.co' => 'docker.elastic.co',
    ];

    private const REPO_PATTERN = '/^[a-z0-9]+([._\/-][a-z0-9]+)*$/';

    private const TAG_PATTERN = '/^[\w][\w.-]{0,127}$/';

    private const DIGEST_PATTERN = '/^sha256:[a-f0-9]{64}$/';

    public function __construct(
        private readonly ?RegistryConnector $connector = null,
    ) {}

    /**
     * Parse and validate a container image reference into registry, host, repo, and tag.
     *
     * @return array{registry: string, host: string, repo: string, tag: string}
     */
    public function parse(string $reference): array
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw new RuntimeException('Image reference cannot be empty.');
        }

        if (str_contains($reference, '@')) {
            throw new RuntimeException("Image reference '{$reference}' must not contain a digest ('@').");
        }

        $lastSlash = strrpos($reference, '/');
        $lastColon = strrpos($reference, ':');

        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $name = substr($reference, 0, $lastColon);
            $tag = substr($reference, $lastColon + 1);
        } else {
            $name = $reference;
            $tag = 'latest';
        }

        if (str_contains($name, '/')) {
            [$firstSegment, $rest] = explode('/', $name, 2);

            if (str_contains($firstSegment, '.') || str_contains($firstSegment, ':')) {
                $registry = $firstSegment;
                $repo = $rest;
            } else {
                $registry = 'docker.io';
                $repo = $name;
            }
        } else {
            $registry = 'docker.io';
            $repo = $name;
        }

        if ($registry === 'docker.io' && $repo !== '' && ! str_contains($repo, '/')) {
            $repo = 'library/'.$repo;
        }

        if (! isset(self::ALLOWED_REGISTRIES[$registry])) {
            throw new RuntimeException("Disallowed or unknown container registry '{$registry}'.");
        }

        if (preg_match(self::REPO_PATTERN, $repo) !== 1) {
            throw new RuntimeException("Invalid image repository '{$repo}' in reference '{$reference}'.");
        }

        if (preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw new RuntimeException("Invalid image tag '{$tag}' in reference '{$reference}'.");
        }

        return [
            'registry' => $registry,
            'host' => self::ALLOWED_REGISTRIES[$registry],
            'repo' => $repo,
            'tag' => $tag,
        ];
    }

    /**
     * Resolve the sha256 manifest digest for an image reference from its upstream registry.
     */
    public function resolve(string $reference): string
    {
        $parsed = $this->parse($reference);
        $host = $parsed['host'];
        $repo = $parsed['repo'];
        $tag = $parsed['tag'];

        $connector = $this->connector ?? new RegistryConnector($host);
        $response = $connector->send(new HeadManifestRequest($host, $repo, $tag));

        if ($response->status() === 401) {
            $challengeHeader = $this->normalizeHeader($response->header('WWW-Authenticate'));
            $challenge = $this->parseBearerChallenge($challengeHeader);
            $token = $this->fetchBearerToken($connector, $challenge['realm'], $challenge['service'], $challenge['scope']);

            $response = $connector->send(new HeadManifestRequest($host, $repo, $tag, $token));
        }

        if (! $response->successful()) {
            throw new RuntimeException("Registry {$host} returned HTTP {$response->status()} when resolving '{$reference}'.");
        }

        $digest = trim($this->normalizeHeader($response->header('Docker-Content-Digest')));

        if (preg_match(self::DIGEST_PATTERN, $digest) !== 1) {
            throw new RuntimeException("Registry {$host} returned invalid or missing Docker-Content-Digest '{$digest}' for '{$reference}'.");
        }

        return $digest;
    }

    /**
     * Return the image reference pinned with its resolved sha256 digest ("repo:tag@sha256:...").
     */
    public function pin(string $reference): string
    {
        $reference = trim($reference);
        $parsed = $this->parse($reference);
        $digest = $this->resolve($reference);

        $lastSlash = strrpos($reference, '/');
        $lastColon = strrpos($reference, ':');
        $hasExplicitTag = $lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash);
        $withTag = $hasExplicitTag ? $reference : "{$reference}:{$parsed['tag']}";

        return "{$withTag}@{$digest}";
    }

    /**
     * @return array{realm: string, service: string|null, scope: string|null}
     */
    private function parseBearerChallenge(string $header): array
    {
        $header = trim($header);

        if ($header === '' || preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            throw new RuntimeException('Registry returned HTTP 401 without a valid WWW-Authenticate Bearer challenge.');
        }

        $params = [];
        if (preg_match_all('/(\w+)="([^"]*)"/', $matches[1], $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                $params[strtolower($pair[1])] = $pair[2];
            }
        }

        $realm = trim((string) ($params['realm'] ?? ''));
        if ($realm === '') {
            throw new RuntimeException('Registry WWW-Authenticate challenge is missing a realm.');
        }

        if (! str_starts_with(strtolower($realm), 'https://') || filter_var($realm, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException("Registry token realm '{$realm}' must use HTTPS.");
        }

        return [
            'realm' => $realm,
            'service' => isset($params['service']) && $params['service'] !== '' ? $params['service'] : null,
            'scope' => isset($params['scope']) && $params['scope'] !== '' ? $params['scope'] : null,
        ];
    }

    private function fetchBearerToken(RegistryConnector $connector, string $realm, ?string $service, ?string $scope): string
    {
        // A connection to the token service's own host: Saloon refuses absolute URLs as paths, so a
        // registry's challenge can never redirect a request carrying another host's credentials.
        $parts = parse_url($realm);
        $host = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $tokenConnector = new RegistryConnector($host);
        $response = $tokenConnector->send(new RegistryTokenRequest(($parts['path'] ?? '') !== '' ? $parts['path'] : '/', $service, $scope));

        if (! $response->successful()) {
            throw new RuntimeException("Registry token endpoint '{$realm}' returned HTTP {$response->status()}.");
        }

        $token = $response->json('token') ?? $response->json('access_token');

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException("Registry token endpoint '{$realm}' did not return a token.");
        }

        return trim($token);
    }

    /**
     * @param  string|array<array-key, mixed>|null  $value
     */
    private function normalizeHeader(string|array|null $value): string
    {
        if (is_array($value)) {
            return (string) ($value[0] ?? '');
        }

        return (string) $value;
    }
}
