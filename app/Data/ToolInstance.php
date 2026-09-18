<?php

namespace App\Data;

use App\Enums\ClusterTool;
use LogicException;

/**
 * One installed instance of a Cluster Tool, and the single place its resource
 * names come from (ADR 0021). `:init`, `:remove`, `:show` and `:wire` ask this
 * object instead of assembling names, so they can't disagree.
 *
 * The instance is always derived from the host (ADR 0012), never passed in.
 */
final readonly class ToolInstance
{
    private function __construct(
        public ClusterTool $tool,
        public string $host,
        public string $instance,
        public ?string $engine,
    ) {}

    public static function forHost(ClusterTool $tool, string $host, ?string $engine = null): self
    {
        return new self($tool, $host, $tool->instanceSlugFromHost($host), $engine);
    }

    public function namespace(): string
    {
        return $this->tool->namespace();
    }

    /** The primary Deployment, or the named component's. */
    public function deployment(?string $component = null): string
    {
        if ($component === null) {
            return $this->tool->deploymentName($this->instance, $this->engine);
        }

        $match = $this->tool->componentByKey($component, $this->instance, $this->engine)
            ?? throw new LogicException("{$this->tool->value} has no '{$component}' component.");

        return $match->deployment;
    }

    /** @return list<ClusterToolComponentData> */
    public function components(): array
    {
        return array_values($this->tool->components($this->instance, $this->engine));
    }

    /** @return list<string> */
    public function commonsDatabases(): array
    {
        return array_values($this->tool->commonsDatabases($this->instance, $this->engine));
    }

    /** @return list<string> */
    public function commonsRedisTenants(): array
    {
        return array_values($this->tool->commonsRedisTenants($this->instance));
    }

    /** @return list<string> */
    public function commonsBuckets(): array
    {
        return array_values($this->tool->commonsBuckets($this->instance, $this->engine));
    }

    /** The Middleware `--vpn-only` attaches, or null when the tool has none. */
    public function vpnMiddleware(): ?ResourceRef
    {
        $target = $this->tool->vpnMiddlewareTarget($this->instance);

        return $target === null ? null : new ResourceRef('Middleware', $target['name'], $target['namespace']);
    }

    /** The Secret holding the Commons database password, or null. */
    public function databaseSecret(): ?ResourceRef
    {
        $ref = $this->tool->dbSecretRef($this->instance, $this->engine);

        return $ref === null ? null : new ResourceRef('Secret', $ref['secret'], $ref['namespace']);
    }
}
