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
 * `forContext(null)` is the current context of ~/.kube/config, for local work
 * only; a cloud environment always resolves to its own saved context or fails.
 * `current()` is plain `kubectl`, following the shell's own KUBECONFIG, for
 * code whose target is by design "the cluster this machine points at".
 */
final readonly class Kubectl
{
    /** @param  list<string>|null  $kubeconfigs  null = ~/.kube/config */
    private function __construct(public ?string $context, private bool $ambient = false, private ?array $kubeconfigs = null, private ?string $rawPrefix = null) {}

    /**
     * A handle for a prefix string a caller already built, so a shared helper
     * that still receives `string $kubectl` can make typed calls. Goes away
     * once every caller passes a handle (Stage 4).
     */
    public static function fromPrefix(string $prefix): self
    {
        return new self(null, rawPrefix: $prefix);
    }

    /**
     * One shell argument: left as-is when it holds only characters the shell
     * never interprets, single-quoted otherwise. As safe as always quoting,
     * and the command reads the way people (and test fakes) write it.
     */
    public static function arg(string $value): string
    {
        if (preg_match('~^[A-Za-z0-9_./:=,@%+-]+$~', $value) === 1) {
            return $value;
        }

        // `name=value`: quote just the value (jsonpath='{...}'); still one word.
        if (preg_match('~^([A-Za-z0-9_./:,@%+-]+=)(.*)$~s', $value, $m) === 1) {
            return $m[1].escapeshellarg($m[2]);
        }

        return escapeshellarg($value);
    }

    /**
     * An explicit kubeconfig file (a scoped deploy credential, k3s's own
     * file), or several merged in order, as KUBECONFIG itself allows
     * (`config view --flatten` over a list is how kubeconfigs are merged).
     *
     * @param  string|list<string>  $kubeconfig
     */
    public static function forKubeconfig(string|array $kubeconfig, ?string $context = null): self
    {
        $paths = array_values(array_filter((array) $kubeconfig, fn (string $path) => $path !== ''));
        if ($paths === []) {
            throw new LogicException('forKubeconfig() needs at least one kubeconfig path.');
        }

        return new self($context !== null && $context !== '' ? $context : null, kubeconfigs: $paths);
    }

    /**
     * Whatever cluster kubectl itself resolves to, KUBECONFIG included: local
     * dev tooling, and commands run on the server they manage (bundle:install
     * on a k3s host whose kubeconfig isn't ~/.kube/config).
     */
    public static function current(): self
    {
        return new self(null, ambient: true);
    }

    public static function forContext(?string $context): self
    {
        return new self($context !== null && $context !== '' ? $context : null);
    }

    /**
     * The cluster an environment is bound to: a managed cluster's stored
     * context, or `larakube-<ip>` for a VPS. `local` is the current context.
     */
    public static function forEnvironment(?ConfigData $config, string $environment): self
    {
        if ($environment === 'local') {
            return new self(null);
        }

        $cloud = $config?->getCloud($environment);
        $context = $cloud?->context ?: ($cloud?->ip ? "larakube-{$cloud->ip}" : null);

        return $context !== null
            ? new self($context)
            : throw new LogicException("'{$environment}' has no saved cluster. Run `larakube cloud:configure {$environment}` first.");
    }

    /**
     * Whether a context name follows a local-cluster convention (OrbStack,
     * Docker Desktop, minikube, kind, colima, or cluster:setup's native k3s).
     * Remote k3s is named "larakube-<ip>", so it stays non-local.
     */
    public static function isLocalContextName(string $context): bool
    {
        $context = strtolower(trim($context));

        foreach (['minikube', 'docker-desktop', 'orbstack', 'kind', 'colima', 'k3s-larakube'] as $keyword) {
            if ($context !== '' && str_contains($context, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** The command prefix, for callers not yet moved onto typed calls. */
    public function prefix(): string
    {
        if ($this->rawPrefix !== null) {
            return $this->rawPrefix;
        }

        if ($this->ambient) {
            return 'kubectl';
        }

        $kubeconfig = implode(':', array_map('escapeshellarg', $this->kubeconfigs ?? [home_path('.kube/config')]));
        $kubectl = 'KUBECONFIG='.$kubeconfig.' kubectl';

        return $this->context !== null ? $kubectl.' --context '.escapeshellarg($this->context) : $kubectl;
    }

    /** Apply a manifest, sent on stdin. */
    /**
     * $serverSide: server-side apply as field manager `larakube`. It keeps no
     * last-applied annotation, which overflows (256 KB) on large objects such
     * as a certificate bundle.
     */
    public function apply(string $yaml, ?string $namespace = null, bool $serverSide = false): KubectlResult
    {
        $mode = $serverSide ? ['--server-side', '--field-manager=larakube', '--force-conflicts'] : [];

        return $this->run(['apply', ...$mode, ...$this->ns($namespace), '-f', '-'], $yaml);
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
        // A dot in a key is a jsonpath separator unless escaped; idempotent for
        // callers that already escaped it.
        $path = str_replace('.', '\.', str_replace('\.', '.', $key));
        $encoded = trim($this->run(['get', 'secret', $name, '-n', $namespace, '-o', "jsonpath={.data.{$path}}"])->output);

        return $encoded !== '' ? (string) base64_decode($encoded) : null;
    }

    /**
     * Create or update a Secret. The values go in the manifest on stdin.
     *
     * @param  array<string, string>  $data
     * @param  array<string, string>  $labels
     */
    public function putSecret(string $namespace, string $name, array $data, array $labels = [], bool $serverSide = false): KubectlResult
    {
        return $this->apply(serverSide: $serverSide, yaml: (string) json_encode([
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
    public function putConfigMap(string $namespace, string $name, array $data, array $labels = [], bool $serverSide = false): KubectlResult
    {
        return $this->apply(serverSide: $serverSide, yaml: (string) json_encode([
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
        $command = $this->prefix().' '.implode(' ', array_map(self::arg(...), $args));
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
