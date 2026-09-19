<?php

namespace App\Data;

use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use Illuminate\Support\Facades\Process;
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

    /** A host as typed or pasted (scheme, path, port, stray dots) reduced to the bare host. */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^[a-z]+://#', '', $host);
        $host = (string) preg_replace('#[/:].*$#', '', $host);

        return trim($host, ". \t");
    }

    public static function forHost(ClusterTool $tool, string $host, ?string $engine = null): self
    {
        return new self($tool, $host, $tool->instanceSlugFromHost($host), $engine);
    }

    /**
     * For code that only has a registered instance slug (vendor definitions,
     * teardown). Names depend on the instance alone, never the host.
     */
    public static function forInstance(ClusterTool $tool, string $instance, ?string $engine = null): self
    {
        if ($instance === '') {
            throw new LogicException("{$tool->value}: an instance is always a host-derived slug, never empty.");
        }

        return new self($tool, '', $instance, $engine);
    }

    /**
     * Every registered instance of $tool on the cluster $kubectl points at,
     * read from the tool registry (the source of truth for instances).
     *
     * @return list<self>
     */
    public static function registered(string $kubectl, ClusterTool $tool): array
    {
        $encoded = trim(Process::run(
            "{$kubectl} get secret larakube-tools-registry -n larakube-shared -o jsonpath=".escapeshellarg('{.data.registry\.json}'),
        )->output());
        $rows = json_decode((string) base64_decode($encoded), true);

        $instances = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $slug = (string) ($row['instance'] ?? '');
            if (($row['tool'] ?? null) === $tool->value && $slug !== '') {
                $instances[] = new self($tool, (string) ($row['host'] ?? ''), $slug, null);
            }
        }

        return $instances;
    }

    /** Whether one of $tool's registered instances runs $component's Deployment. */
    public static function componentDeployed(string $kubectl, ClusterTool $tool, ?string $component = null): bool
    {
        foreach (self::registered($kubectl, $tool) as $instance) {
            $deployment = $instance->deployment($component);
            $found = trim(Process::run(
                "{$kubectl} get deployment {$deployment} -n {$instance->namespace()} -o name --ignore-not-found",
            )->output());

            if ($found !== '') {
                return true;
            }
        }

        return false;
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
