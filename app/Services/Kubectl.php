<?php

namespace App\Services;

use App\Data\ConfigData;
use App\Data\KubectlResult;
use App\Data\ResourceRef;
use Illuminate\Support\Facades\Process;
use LogicException;
use stdClass;

/**
 * One cluster, and the only way to talk to it: every call goes to the same
 * kubeconfig and context, and secret values travel on stdin, never in argv.
 *
 * `forContext(null)` is the current context and is meant for local work only;
 * a cloud environment always resolves to its own saved context or fails.
 */
final readonly class Kubectl
{
    private function __construct(public ?string $context) {}

    public static function forContext(?string $context): self
    {
        return new self($context !== null && $context !== '' ? $context : null);
    }

    /**
     * The cluster an environment is bound to: a managed cluster's stored
     * context, or `larakube-<ip>` for a VPS. `local` is the current context.
     */
    public static function forEnvironment(ConfigData $config, string $environment): self
    {
        if ($environment === 'local') {
            return new self(null);
        }

        $cloud = $config->getCloud($environment);
        $context = $cloud?->context ?: ($cloud?->ip ? "larakube-{$cloud->ip}" : null);

        return $context !== null
            ? new self($context)
            : throw new LogicException("'{$environment}' has no saved cluster. Run `larakube cloud:configure {$environment}` first.");
    }

    /** The command prefix, for callers not yet moved onto typed calls. */
    public function prefix(): string
    {
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $this->context !== null ? $kubectl.' --context '.escapeshellarg($this->context) : $kubectl;
    }

    /** Apply a manifest, sent on stdin. */
    public function apply(string $yaml, ?string $namespace = null): KubectlResult
    {
        return $this->run(['apply', ...$this->ns($namespace), '-f', '-'], $yaml);
    }

    /** Delete resources, one call per namespace. Missing ones are not an error. */
    public function delete(ResourceRef ...$refs): KubectlResult
    {
        $output = '';
        foreach ($this->byNamespace($refs) as $namespace => $group) {
            $result = $this->run(['delete', ...array_map(fn (ResourceRef $ref) => $ref->ref(), $group), '-n', $namespace, '--ignore-not-found']);
            if (! $result->ok) {
                return $result;
            }
            $output .= $result->output;
        }

        return new KubectlResult(true, $output);
    }

    /** The resource as decoded JSON, or null when it doesn't exist. */
    public function get(ResourceRef $ref): ?array
    {
        return $this->run(['get', $ref->ref(), '-n', $ref->namespace, '-o', 'json', '--ignore-not-found'])->json();
    }

    public function exists(ResourceRef $ref): bool
    {
        return trim($this->run(['get', $ref->ref(), '-n', $ref->namespace, '-o', 'name', '--ignore-not-found'])->output) !== '';
    }

    /**
     * Resources of one kind, optionally filtered by labels.
     *
     * @param  array<string, string>  $labels
     * @return list<array<string, mixed>>
     */
    public function list(string $kind, string $namespace, array $labels = []): array
    {
        $selector = $labels === [] ? [] : ['-l', implode(',', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($labels), $labels))];

        return array_values($this->run(['get', $kind, '-n', $namespace, ...$selector, '-o', 'json'])->json()['items'] ?? []);
    }

    /** One decoded value from a Secret, or null when the Secret or key is missing. */
    public function secretValue(string $namespace, string $name, string $key): ?string
    {
        // A dot in a key is a jsonpath separator unless escaped.
        $path = str_replace('.', '\.', $key);
        $encoded = trim($this->run(['get', 'secret', $name, '-n', $namespace, '-o', "jsonpath={.data.{$path}}"])->output);

        return $encoded !== '' ? (string) base64_decode($encoded) : null;
    }

    /**
     * Create or update a Secret. The values go in the manifest on stdin.
     *
     * @param  array<string, string>  $data
     * @param  array<string, string>  $labels
     */
    public function putSecret(string $namespace, string $name, array $data, array $labels = []): KubectlResult
    {
        return $this->apply((string) json_encode([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => array_filter(['name' => $name, 'namespace' => $namespace, 'labels' => $labels ?: null]),
            'type' => 'Opaque',
            'data' => array_map(fn (string $value) => base64_encode($value), $data),
        ]));
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, string>  $labels
     */
    public function putConfigMap(string $namespace, string $name, array $data, array $labels = []): KubectlResult
    {
        return $this->apply((string) json_encode([
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => array_filter(['name' => $name, 'namespace' => $namespace, 'labels' => $labels ?: null]),
            'data' => $data === [] ? new stdClass : $data,
        ]));
    }

    /**
     * Run a command in a pod (`deploy/<name>` picks one of its pods).
     *
     * @param  list<string>  $command
     */
    public function exec(string $namespace, string $target, array $command, ?string $stdin = null, ?string $container = null): KubectlResult
    {
        return $this->run([
            'exec', ...($stdin !== null ? ['-i'] : []), '-n', $namespace, $target,
            ...($container !== null ? ['-c', $container] : []), '--', ...$command,
        ], $stdin);
    }

    public function rolloutStatus(string $namespace, string $deployment, int $timeoutSeconds = 120): KubectlResult
    {
        return $this->run(['rollout', 'status', "deployment/{$deployment}", '-n', $namespace, "--timeout={$timeoutSeconds}s"], null, $timeoutSeconds + 10);
    }

    public function rolloutRestart(string $namespace, string $deployment): KubectlResult
    {
        return $this->run(['rollout', 'restart', "deployment/{$deployment}", '-n', $namespace]);
    }

    /**
     * Anything the typed methods don't cover yet. Kept deliberately visible:
     * its use should only shrink.
     *
     * @param  list<string>  $args
     */
    public function raw(array $args, ?string $stdin = null): KubectlResult
    {
        return $this->run($args, $stdin);
    }

    /** @param  list<string>  $args */
    private function run(array $args, ?string $stdin = null, ?int $timeout = null): KubectlResult
    {
        $command = $this->prefix().' '.implode(' ', array_map('escapeshellarg', $args));
        $process = $timeout !== null ? Process::timeout($timeout) : Process::timeout(120);
        $result = ($stdin !== null ? $process->input($stdin) : $process)->run($command);

        return new KubectlResult($result->successful(), $result->output(), $result->errorOutput());
    }

    /** @return list<string> */
    private function ns(?string $namespace): array
    {
        return $namespace !== null ? ['-n', $namespace] : [];
    }

    /**
     * @param  array<ResourceRef>  $refs
     * @return array<string, list<ResourceRef>>
     */
    private function byNamespace(array $refs): array
    {
        $groups = [];
        foreach ($refs as $ref) {
            $groups[$ref->namespace][] = $ref;
        }

        return $groups;
    }
}
