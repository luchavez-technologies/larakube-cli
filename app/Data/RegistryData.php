<?php

namespace App\Data;

use App\Enums\RegistryProvider;
use Spatie\LaravelData\Data;

/**
 * Container registry configuration for a specific environment.
 * Lives inside EnvironmentData::$registry (nullable).
 * Example: {"provider": "ghcr", "image": "owner/repo"}
 */
class RegistryData extends Data
{
    public function __construct(
        /**
         * Registry provider: ghcr or dockerhub (ecr/gar disabled for now).
         */
        public RegistryProvider $provider,
        /**
         * Image repository path. Defaults per provider:
         *   GHCR: "owner/repo" (resolves to ghcr.io/owner/repo)
         *   Docker Hub: "owner/repo" (resolves to docker.io/owner/repo)
         */
        public ?string $image = null,
        /**
         * The registry host, e.g. for custom Gitea registries.
         */
        public ?string $host = null,
    ) {}

    public function getRegistryHost(): string
    {
        if ($this->provider === RegistryProvider::GITEA && $this->host) {
            return $this->host;
        }

        return $this->provider->registryHost();
    }

    public function getFullImageReference(string $tag = 'latest'): string
    {
        $host = $this->getRegistryHost();
        $image = $this->image ?? 'app';

        return "{$host}/{$image}:{$tag}";
    }

    /**
     * A digest-pinned reference (`host/image@sha256:…`) — immutable, since a
     * registry can't repoint a digest to different bytes the way it can a tag.
     * $digest is the full `sha256:…` string.
     */
    public function getDigestReference(string $digest): string
    {
        $host = $this->getRegistryHost();
        $image = $this->image ?? 'app';

        return "{$host}/{$image}@{$digest}";
    }
}
