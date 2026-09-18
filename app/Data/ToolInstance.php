<?php

namespace App\Data;

use App\Enums\ClusterTool;
use App\Enums\SecretKind;
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

    /**
     * `{category}-{component}`: the component's Deployment name without the
     * instance, the stem every other resource of that component shares.
     */
    public function base(?string $component = null): string
    {
        $deployment = $this->deployment($component);
        $suffix = "-{$this->instance}";

        return str_ends_with($deployment, $suffix) ? substr($deployment, 0, -strlen($suffix)) : $deployment;
    }

    /** `{category}-{component}-{token}-{instance}` (ADR 0021). */
    public function name(string $token, ?string $component = null): string
    {
        return "{$this->base($component)}-{$token}-{$this->instance}";
    }

    public function secret(SecretKind $kind = SecretKind::CREDENTIALS, ?string $component = null): string
    {
        return $this->name($kind->value, $component);
    }

    public function configMap(string $key, ?string $component = null): string
    {
        return $this->name($key, $component);
    }

    public function volume(string $key = 'storage', ?string $component = null): string
    {
        return $this->name($key, $component);
    }

    /** The one Commons database this instance owns. */
    public function database(): string
    {
        return $this->commonsDatabases()[0] ?? throw new LogicException("{$this->tool->value} uses no Commons database.");
    }

    /** The one Commons Redis tenant this instance owns. */
    public function redisTenant(): string
    {
        return $this->commonsRedisTenants()[0] ?? throw new LogicException("{$this->tool->value} uses no Commons Redis.");
    }

    /** A Commons bucket this instance owns: the first, or the one whose base name is $base. */
    public function bucket(?string $base = null): string
    {
        foreach ($this->commonsBuckets() as $bucket) {
            if ($base === null || $bucket === "{$base}-{$this->instance}") {
                return $bucket;
            }
        }

        throw new LogicException("{$this->tool->value} declares no Commons bucket".($base !== null ? " '{$base}'" : '').'.');
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
