<?php

namespace App\Services;

use App\Data\InstanceData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * The cluster's tool registry: one flat list of rows in the
 * `larakube-tools-registry` Secret, each carrying its own `tool`, so "every
 * instance of X" is a filter, not a lookup. Instances are host-derived slugs
 * (ADR 0012); the host is the identity.
 *
 * Data only: it never prompts or prints. Reads are memoised for the life of
 * the object and refreshed by every write.
 */
class ToolRegistry
{
    public const string SECRET = 'larakube-tools-registry';

    public const string NAMESPACE = 'larakube-shared';

    /** Tests install a FakeToolRegistry here (FakeToolRegistry::install()). */
    private static ?Closure $resolver = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    final protected function __construct(protected readonly string $kubectl) {}

    /** The registry on the cluster $cluster points at (a Kubectl handle or, until Stage 4, its prefix). */
    public static function on(Kubectl|string $cluster): static
    {
        $kubectl = $cluster instanceof Kubectl ? $cluster->prefix() : $cluster;

        return self::$resolver !== null ? (self::$resolver)($kubectl) : new static($kubectl);
    }

    /** @internal for FakeToolRegistry */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows ??= $this->read();
    }

    /** @return list<array<string, mixed>> */
    public function entries(ClusterTool $tool): array
    {
        return array_values(array_filter($this->rows(), fn (array $row) => ($row['tool'] ?? null) === $tool->value));
    }

    /**
     * The row for $tool at $instance. A null $instance means "no preference":
     * the tool's sole row, or null when it has several (never a guess).
     *
     * @return array<string, mixed>|null
     */
    public function entry(ClusterTool $tool, ?string $instance = null): ?array
    {
        $index = $this->matchIndex($this->rows(), $tool, $instance);

        return $index === null ? null : $this->rows()[$index];
    }

    /** @return array<string, mixed>|null */
    public function entryForHost(ClusterTool $tool, string $host): ?array
    {
        foreach ($this->entries($tool) as $row) {
            if (($row['host'] ?? null) === $host) {
                return $row;
            }
        }

        return null;
    }

    public function has(ClusterTool $tool, ?string $instance = null): bool
    {
        return $this->entry($tool, $instance) !== null;
    }

    /** @return list<string> every registered instance slug of $tool */
    public function instanceSlugs(ClusterTool $tool): array
    {
        return array_column($this->entries($tool), 'instance');
    }

    /** @return list<InstanceData> */
    public function instances(ClusterTool $tool): array
    {
        return array_map(fn (array $row) => InstanceData::from($row), $this->entries($tool));
    }

    public function instance(ClusterTool $tool, ?string $instance = null): ?InstanceData
    {
        $row = $this->entry($tool, $instance);

        return $row === null ? null : InstanceData::from($row);
    }

    public function host(ClusterTool $tool, ?string $instance = null): ?string
    {
        return $this->entry($tool, $instance)['host'] ?? null;
    }

    /** @return list<string> */
    public function hosts(ClusterTool $tool): array
    {
        return array_values(array_unique(array_filter(array_map(fn (array $row) => (string) ($row['host'] ?? ''), $this->entries($tool)))));
    }

    /** @return list<string> */
    public function aliases(ClusterTool $tool, ?string $instance = null): array
    {
        return $this->entry($tool, $instance)['aliases'] ?? [];
    }

    /**
     * Every instance registered for $host. Host identity wins over slug
     * derivation, so a host registered twice returns both (removal takes down
     * everything serving it). An unregistered host derives a fresh slug; no
     * host at all (or "all") returns every instance of $tool, or [''] when
     * nothing is registered.
     *
     * @return list<string>
     */
    public function targetsForHost(ClusterTool $tool, string $host): array
    {
        $host = trim($host);
        if ($host === '' || $host === 'all') {
            $instances = array_values(array_unique(array_map(fn (array $row) => (string) ($row['instance'] ?? ''), $this->entries($tool))));

            return $instances !== [] ? $instances : [''];
        }

        $host = ToolInstance::normalizeHost($host);
        $instances = array_values(array_unique(array_map(
            fn (array $row) => (string) ($row['instance'] ?? ''),
            array_filter($this->entries($tool), fn (array $row) => ($row['host'] ?? null) === $host),
        )));

        return $instances !== [] ? $instances : [$tool->instanceSlugFromHost($host)];
    }

    /**
     * The one instance $host refers to: a real registered slug over a stale ''
     * row, else a slug derived from the host.
     */
    public function instanceForHost(ClusterTool $tool, string $host): string
    {
        $real = array_values(array_filter($this->targetsForHost($tool, $host), fn (string $instance) => $instance !== ''));
        if ($real !== []) {
            return $real[0];
        }

        $host = trim($host);

        return ($host === '' || $host === 'all') ? '' : $tool->instanceSlugFromHost(ToolInstance::normalizeHost($host));
    }

    /**
     * Record or update an instance. Only a legacy ''/'main' row is healed onto
     * a new slug; a row with a real, different slug is a different instance.
     */
    public function register(ClusterTool $tool, array $metadata = [], ?string $instance = null): bool
    {
        $rows = $this->rows();
        $metadata = array_filter($metadata, fn ($value) => $value !== null && $value !== '');
        $now = Carbon::now()->toIso8601String();
        $index = $this->matchIndex($rows, $tool, $instance, selfHeal: true);

        if ($index !== null) {
            $rows[$index] = array_merge($rows[$index], $metadata, $instance !== null ? ['instance' => $instance] : [], ['updatedAt' => $now]);
        } else {
            $rows[] = array_merge(['tool' => $tool->value, 'instance' => $instance, 'aliases' => [], 'installedAt' => $now], $metadata, ['updatedAt' => $now]);
        }

        return $this->save($rows);
    }

    public function unregister(ClusterTool $tool, ?string $instance = null): bool
    {
        $rows = $this->rows();
        $index = $this->matchIndex($rows, $tool, $instance);
        if ($index === null) {
            return true;
        }

        unset($rows[$index]);

        return $this->save(array_values($rows));
    }

    public function addAlias(ClusterTool $tool, string $alias, ?string $instance = null): bool
    {
        $rows = $this->rows();
        $index = $this->matchIndex($rows, $tool, $instance);
        if ($index === null) {
            return false;
        }

        $rows[$index]['aliases'] = array_values(array_unique([...($rows[$index]['aliases'] ?? []), $alias]));

        return $this->save($rows);
    }

    public function removeAlias(ClusterTool $tool, string $alias, ?string $instance = null): bool
    {
        $rows = $this->rows();
        $index = $this->matchIndex($rows, $tool, $instance);
        if ($index === null) {
            return true;
        }

        $rows[$index]['aliases'] = array_values(array_filter($rows[$index]['aliases'] ?? [], fn ($host) => $host !== $alias));

        return $this->save($rows);
    }

    /** Replace the whole list (tool:list --refresh rebuilds it). */
    public function replace(array $rows): bool
    {
        return $this->save(array_values($rows));
    }

    /** @return list<array<string, mixed>> */
    protected function read(): array
    {
        $encoded = trim(Process::run(
            "{$this->kubectl} get secret ".self::SECRET.' -n '.self::NAMESPACE." -o jsonpath='{.data.registry\\.json}'",
        )->output());
        $decoded = $encoded === '' ? null : json_decode((string) base64_decode($encoded), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function write(array $rows): bool
    {
        Process::run("{$this->kubectl} create namespace ".self::NAMESPACE." --dry-run=client -o yaml | {$this->kubectl} apply -f -");

        // A file, not --from-literal: the list holds every instance of every tool.
        $directory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $file = $directory->path().'/registry.json';
        file_put_contents($file, json_encode($rows));

        $ok = Process::run(
            "{$this->kubectl} create secret generic ".self::SECRET.' -n '.self::NAMESPACE
            ." --from-file=registry.json={$file} --dry-run=client -o yaml | {$this->kubectl} apply -f -",
        )->successful();
        $directory->delete();

        return $ok;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function save(array $rows): bool
    {
        $ok = $this->write(array_values($rows));
        $this->rows = null;

        return $ok;
    }

    /**
     * Which row is $tool at $instance. With $selfHeal (register() only), an
     * instance that matches nothing may heal onto the tool's sole row, but
     * only when that row still carries a legacy ''/null/'main' instance.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function matchIndex(array $rows, ClusterTool $tool, ?string $instance, bool $selfHeal = false): ?int
    {
        $forTool = array_keys(array_filter($rows, fn (array $row) => ($row['tool'] ?? null) === $tool->value));

        if ($instance === null) {
            return count($forTool) === 1 ? $forTool[0] : null;
        }

        foreach ($forTool as $i) {
            if (($rows[$i]['instance'] ?? null) === $instance) {
                return $i;
            }
        }

        if ($selfHeal && count($forTool) === 1) {
            $stored = $rows[$forTool[0]]['instance'] ?? null;

            return in_array($stored, [null, '', 'main'], true) ? $forTool[0] : null;
        }

        return null;
    }
}
