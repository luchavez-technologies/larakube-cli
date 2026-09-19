<?php

namespace Tests\Support;

use App\Data\ResourceRef;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * An in-memory cluster behind App\Services\Kubectl, for tests. It understands
 * the argument lists Kubectl sends, so tests assert on objects ("this Secret
 * exists with this value") rather than on command strings.
 *
 * Any command that isn't a Kubectl call gets an empty success, like a
 * catch-all Process::fake().
 */
final class FakeKubectl
{
    /** @var array<string, array<string, mixed>> key => object */
    private array $objects = [];

    /** @var list<ResourceRef> */
    private array $deleted = [];

    /** @var list<array{namespace: string, target: string, command: list<string>, stdin: ?string}> */
    private array $execs = [];

    /** @var list<list<string>> every argument list received */
    private array $calls = [];

    public static function install(): self
    {
        $fake = new self;
        Process::fake(['*' => fn (PendingProcess $process) => $fake->handle($process)]);

        return $fake;
    }

    /** Seed an object as if it already existed on the cluster. */
    public function with(array $object): self
    {
        $this->objects[$this->key($object)] = $object;

        return $this;
    }

    public function has(ResourceRef $ref): bool
    {
        return isset($this->objects[$this->refKey($ref)]);
    }

    public function object(ResourceRef $ref): ?array
    {
        return $this->objects[$this->refKey($ref)] ?? null;
    }

    /** A Secret's decoded value, as the cluster would hold it. */
    public function secretValue(string $namespace, string $name, string $key): ?string
    {
        $data = $this->object(new ResourceRef('Secret', $name, $namespace))['data'][$key] ?? null;

        return $data !== null ? (string) base64_decode((string) $data) : null;
    }

    /** @return list<ResourceRef> */
    public function deleted(): array
    {
        return $this->deleted;
    }

    /** @return list<array{namespace: string, target: string, command: list<string>, stdin: ?string}> */
    public function execs(): array
    {
        return $this->execs;
    }

    /** @return list<list<string>> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<string> */
    public static function shellWords(string $args): array
    {
        preg_match_all("/'((?:[^']|'\\\\'')*)'|([^\\s']+)/", $args, $tokens, PREG_SET_ORDER);

        return array_map(
            fn (array $t) => isset($t[2]) && $t[2] !== '' ? $t[2] : str_replace("'\\''", "'", $t[1]),
            $tokens,
        );
    }

    private function handle(PendingProcess $process)
    {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $args = $this->kubectlArgs($command);

        if ($args === null) {
            return Process::result(output: '');
        }

        $this->calls[] = $args;
        $namespace = $this->option($args, '-n');

        return match ($args[0] ?? '') {
            'apply' => $this->apply((string) $process->input, $namespace),
            'delete' => $this->delete($args, (string) $namespace),
            'get' => $this->get($args, (string) $namespace),
            'exec' => $this->exec($args, (string) $namespace, $process->input !== null ? (string) $process->input : null),
            default => Process::result(output: ''),
        };
    }

    /**
     * The arguments after `kubectl` (and its `--context`), or null when the
     * command isn't a Kubectl call. Arguments are bare or single-quoted.
     *
     * @return list<string>|null
     */
    private function kubectlArgs(string $command): ?array
    {
        if (preg_match("/(?:^| )kubectl(?: --context '(?:[^']|'\\\\'')*')? (.*)$/s", $command, $m) !== 1) {
            return null;
        }

        return self::shellWords($m[1]);
    }

    private function apply(string $manifest, ?string $namespace)
    {
        foreach (preg_split('/^---\s*$/m', $manifest) ?: [] as $document) {
            try {
                $object = json_decode($document, true) ?? Yaml::parse($document);
            } catch (Throwable) {
                continue;
            }

            if (is_array($object) && isset($object['kind'], $object['metadata']['name'])) {
                $object['metadata']['namespace'] ??= $namespace ?? 'default';
                $this->objects[$this->key($object)] = $object;
            }
        }

        return Process::result(output: 'configured');
    }

    /** @param  list<string>  $args */
    private function delete(array $args, string $namespace)
    {
        foreach (array_slice($args, 1) as $arg) {
            if (! str_contains($arg, '/') || str_starts_with($arg, '-')) {
                continue;
            }

            [$kind, $name] = explode('/', $arg, 2);
            $ref = new ResourceRef($kind, $name, $namespace);
            $this->deleted[] = $ref;
            unset($this->objects[$this->refKey($ref)]);
        }

        return Process::result(output: 'deleted');
    }

    /** @param  list<string>  $args */
    private function get(array $args, string $namespace)
    {
        $target = $args[1] ?? '';
        $output = $this->option($args, '-o') ?? '';

        // `get secret NAME ... -o jsonpath={.data.KEY}`
        if ($target === 'secret' && str_starts_with($output, 'jsonpath={.data.')) {
            $key = str_replace('\.', '.', substr($output, strlen('jsonpath={.data.'), -1));
            $value = $this->object(new ResourceRef('Secret', $args[2], $namespace))['data'][$key] ?? '';

            return Process::result(output: (string) $value);
        }

        if (str_contains($target, '/')) {
            [$kind, $name] = explode('/', $target, 2);
            $object = $this->object(new ResourceRef($kind, $name, $namespace));

            return Process::result(output: match (true) {
                $object === null => '',
                $output === 'name' => "{$kind}/{$name}",
                default => (string) json_encode($object),
            });
        }

        // `get KIND -n NS [-l k=v] -o json`
        $selector = $this->option($args, '-l');
        $items = array_values(array_filter($this->objects, function (array $object) use ($target, $namespace, $selector): bool {
            if (strtolower($object['kind']) !== strtolower($target) || $object['metadata']['namespace'] !== $namespace) {
                return false;
            }

            foreach ($selector !== null ? explode(',', $selector) : [] as $pair) {
                [$k, $v] = explode('=', $pair, 2);
                if (($object['metadata']['labels'][$k] ?? null) !== $v) {
                    return false;
                }
            }

            return true;
        }));

        return Process::result(output: (string) json_encode(['items' => $items]));
    }

    /** @param  list<string>  $args */
    private function exec(array $args, string $namespace, ?string $stdin)
    {
        $separator = array_search('--', $args, true);
        $flags = array_slice($args, 1, $separator === false ? null : $separator - 1);
        $target = array_values(array_filter($flags, fn (string $a) => str_contains($a, '/')))[0] ?? '';

        $this->execs[] = [
            'namespace' => $namespace,
            'target' => $target,
            'command' => $separator === false ? [] : array_values(array_slice($args, $separator + 1)),
            'stdin' => $stdin,
        ];

        return Process::result(output: '');
    }

    /** @param  list<string>  $args */
    private function option(array $args, string $flag): ?string
    {
        $index = array_search($flag, $args, true);

        return $index !== false ? ($args[$index + 1] ?? null) : null;
    }

    private function key(array $object): string
    {
        return $this->refKey(new ResourceRef($object['kind'], $object['metadata']['name'], $object['metadata']['namespace'] ?? 'default'));
    }

    private function refKey(ResourceRef $ref): string
    {
        return strtolower($ref->key());
    }
}
