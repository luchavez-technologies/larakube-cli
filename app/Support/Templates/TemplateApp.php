<?php

namespace App\Support\Templates;

use App\Contracts\HasImages;
use App\Enums\CacheDriver;
use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Enums\RenderDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;

/**
 * Anything whose templates LaraKube Cloud publishes as a signed release: a Cluster Tool, or Plex
 * Commons, the shared databases and storage other apps use. Plex Commons isn't a Cluster Tool, so the
 * release builder, the template store and the templates:* commands go through this instead.
 */
final readonly class TemplateApp
{
    public const PLEX = 'plex';

    private const PLEX_CONTRACT = 1;

    private function __construct(
        public string $slug,
        public string $folder,
        public ?ClusterTool $tool,
    ) {}

    public static function find(string $slug): ?self
    {
        $slug = strtolower(trim($slug));

        if ($slug === self::PLEX) {
            return new self(self::PLEX, 'plex', null);
        }

        $tool = ClusterTool::tryFrom($slug);

        return $tool === null ? null : self::forTool($tool);
    }

    public static function forTool(ClusterTool $tool): self
    {
        return new self($tool->value, $tool->templateFolder(), $tool);
    }

    /** The app whose templates live in this k8s view folder. */
    public static function forFolder(string $folder): ?self
    {
        if ($folder === 'plex') {
            return self::find(self::PLEX);
        }

        foreach (ClusterTool::cases() as $tool) {
            if ($tool->templateFolder() === $folder) {
                return self::forTool($tool);
            }
        }

        return null;
    }

    public function defaultEngine(): ?string
    {
        return $this->tool?->defaultEngine();
    }

    /** @return list<int> */
    public function contracts(?string $engine): array
    {
        return $this->tool === null
            ? [self::PLEX_CONTRACT]
            : array_values(array_map('intval', $this->tool->templateContracts($engine)));
    }

    /** @return array<string, string> */
    public function images(?string $engine): array
    {
        if ($this->tool === null) {
            return self::plexImages();
        }

        $vendor = $this->tool->vendor($engine);

        return $vendor instanceof HasImages ? $vendor->images() : [];
    }

    /**
     * Every image Plex Commons can run, from the same driver enums its spec defaults use.
     *
     * @return array<string, string>
     */
    private static function plexImages(): array
    {
        return array_filter([
            'postgres' => DatabaseDriver::PGVECTOR_IMAGE,
            'postgres-exporter' => DatabaseDriver::POSTGRESQL->exporterImage(),
            'postgres-pooler' => DatabaseDriver::POSTGRESQL->poolerImage(),
            'mysql' => DatabaseDriver::MYSQL->getDockerImage(),
            'mysql-exporter' => DatabaseDriver::MYSQL->exporterImage(),
            'mariadb' => DatabaseDriver::MARIADB->getDockerImage(),
            'redis' => CacheDriver::REDIS->getDockerImage(),
            'redis-exporter' => CacheDriver::REDIS->exporterImage(),
            'meilisearch' => SearchDriver::MEILISEARCH->getDockerImage(),
            'seaweedfs' => StorageDriver::SEAWEEDFS->getDockerImage(),
            'garage' => StorageDriver::GARAGE->getDockerImage(),
            'headless-shell' => RenderDriver::HEADLESS_CHROME->getDockerImage(),
        ], fn (?string $image): bool => is_string($image) && $image !== '');
    }
}
