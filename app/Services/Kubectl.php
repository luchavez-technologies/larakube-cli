<?php

namespace App\Services;

use App\Data\ConfigData;
use App\Data\KubectlResult;
use App\Data\ResourceRef;
use App\Support\Os;
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

        if ($context === '') {
            return false;
        }

        if (str_starts_with($context, 'larakube-') && $context !== 'k3s-larakube') {
            return false;
        }

        foreach (['minikube', 'docker-desktop', 'docker-for-desktop', 'orbstack', 'kind', 'colima', 'k3s-larakube', 'k3d', 'rancher-desktop', 'microk8s'] as $keyword) {
            if (str_contains($context, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The command prefix, for callers not yet moved onto typed calls.
     *
     * `Os::isWindows()` is only ever true when this process itself was
     * dispatched natively on Windows (`cloud:create`, `setup`, `update` —
     * see `WslBridge::shouldDispatchToWsl()`); every other command still
     * runs inside the larakube-ubuntu WSL distro, where `Os::family()`
     * reports `Linux` like any other process in there, so the POSIX branch
     * below is unchanged for the hundreds of existing callers outside the
     * cloud:create call graph.
     */
    public function prefix(): string
    {
        if ($this->rawPrefix !== null) {
            return $this->rawPrefix;
        }

        if ($this->ambient) {
            return 'kubectl';
        }

        $paths = $this->kubeconfigs ?? [home_path('.kube/config')];

        if (Os::isWindows()) {
            $kubectl = count($paths) === 1
                // A plain --kubeconfig argument composes safely inside a
                // pipeline (several call sites build `{$kubectl} ... | {$kubectl}
                // ...`), unlike `set VAR=value&& command`: cmd.exe parses
                // `&&` and `|` together, so a `set ...&&` prefix on BOTH
                // sides of a pipe reassigns the pipe's source to the `set`
                // command itself rather than to kubectl's own stdout.
                ? 'kubectl --kubeconfig '.escapeshellarg($paths[0])
                // Multiple merged kubeconfigs have no single-flag
                // equivalent — only ever used for a non-piped `config view
                // --flatten` merge (ProvisionsK3sNode::syncKubeconfig()),
                // so the pipe hazard above doesn't apply to this branch.
                // Not escapeshellarg()'d: cmd's `set` takes everything
                // after `=` literally up to `&`/end of line, so quoting
                // here would embed literal quote characters into
                // KUBECONFIG itself rather than delimiting the value.
                : 'set KUBECONFIG='.implode(Os::pathSeparator(), $paths).'&& kubectl';
        } else {
            $kubectl = 'KUBECONFIG='.implode(Os::pathSeparator(), array_map('escapeshellarg', $paths)).' kubectl';
        }

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

    /**
     * Whether a Deployment is present — the "is this tool installed?" probe.
     * `--no-headers` rather than `-o name`: the shape every fake already uses.
     */
    public function hasDeployment(string $namespace, string $name): bool
    {
        return trim($this->run(['get', 'deployment', $name, '-n', $namespace, '--no-headers', '--ignore-not-found'])->output) !== '';
    }

    /** Whether any Deployment in $namespace carries this label selector. */
    public function hasDeploymentLabelled(string $namespace, string $selector): bool
    {
        return trim($this->run(['get', 'deployment', '-n', $namespace, '-l', $selector, '--no-headers', '--ignore-not-found'])->output) !== '';
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

        $decoded = $encoded !== '' ? base64_decode($encoded, true) : false;

        return $decoded !== false ? $decoded : null;
    }

    /**
     * Create or update a Secret. The values go in the manifest on stdin.
     *
     * @param  array<string, string|null>  $data  null is stored as an empty value
     * @param  array<string, string>  $labels
     */
    public function putSecret(string $namespace, string $name, array $data, array $labels = [], bool $serverSide = false): KubectlResult
    {
        $encoded = array_map(fn ($value) => base64_encode((string) $value), $data);

        return $this->apply(serverSide: $serverSide, yaml: (string) json_encode([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => array_filter(['name' => $name, 'namespace' => $namespace, 'labels' => $labels ?: null]),
            'type' => 'Opaque',
            // An empty PHP array json-encodes to `[]`, which Kubernetes
            // rejects for a Secret's `data` (it expects an object/map).
            'data' => $encoded === [] ? new stdClass : $encoded,
        ]));
    }

    /**
     * Set some keys on an existing Secret, leaving its other keys alone. Fails
     * when the Secret doesn't exist. The values go on stdin, never in argv.
     *
     * A JSON Merge Patch (RFC 7386) drops a map key whose value is `null`
     * rather than storing it — so `null` here means "remove this key",
     * unlike `putSecret()`'s full-replace `null` (= stored as empty).
     *
     * @param  array<string, string|null>  $data
     */
    public function patchSecret(string $namespace, string $name, array $data): KubectlResult
    {
        return $this->run(
            ['patch', 'secret', $name, '-n', $namespace, '--type=merge', '--patch-file=/dev/stdin'],
            (string) json_encode(['data' => array_map(fn (?string $value) => $value === null ? null : base64_encode($value), $data)]),
        );
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
     * Set some keys on an existing ConfigMap, leaving its other keys alone.
     * Fails when the ConfigMap doesn't exist. `null` removes a key — see
     * `patchSecret()`'s doc for why.
     *
     * @param  array<string, string|null>  $data
     */
    public function patchConfigMap(string $namespace, string $name, array $data): KubectlResult
    {
        return $this->run(
            ['patch', 'configmap', $name, '-n', $namespace, '--type=merge', '--patch-file=/dev/stdin'],
            (string) json_encode(['data' => $data]),
        );
    }

    /**
     * Idempotently ensure a Deployment's named container has `envFrom`
     * entries for the given ConfigMap/Secret refs, without disturbing any
     * `envFrom`/`env` entries already there. Every tool's OWN env wiring is
     * baked into its Blade template at `apply()` time (ADR: manifest
     * operations stay command-driven) — this is the one exception, for a
     * ConfigMap/Secret pair that doesn't exist until the first custom env
     * var is set, so there's nothing to template ahead of time.
     *
     * $container names the one container to wire — omit it to target the
     * Deployment's first container (every Cluster Tool manifest defines
     * exactly one app container; naming it explicitly is only needed for a
     * multi-container Deployment).
     *
     * @param  list<array{kind: 'configMapRef'|'secretRef', name: string}>  $envFromRefs
     */
    public function ensureDeploymentEnvFrom(string $namespace, string $deployment, array $envFromRefs, ?string $container = null): KubectlResult
    {
        $ref = new ResourceRef('Deployment', $deployment, $namespace);
        $current = $this->get($ref);
        if ($current === null) {
            return new KubectlResult(false, '', "deployment/{$deployment} not found in {$namespace}");
        }

        $containers = $current['spec']['template']['spec']['containers'] ?? [];
        $index = null;
        foreach ($containers as $i => $c) {
            if ($container === null ? $i === 0 : ($c['name'] ?? null) === $container) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            $label = $container ?? '(first container)';

            return new KubectlResult(false, '', "container '{$label}' not found on deployment/{$deployment}");
        }
        $container ??= (string) ($containers[$index]['name'] ?? '');

        /** @var list<array<string, mixed>> $existingEnvFrom */
        $existingEnvFrom = $containers[$index]['envFrom'] ?? [];
        $known = [];
        foreach ($existingEnvFrom as $entry) {
            foreach (['configMapRef', 'secretRef'] as $kind) {
                if (isset($entry[$kind]['name'])) {
                    $known["{$kind}:{$entry[$kind]['name']}"] = true;
                }
            }
        }

        $merged = $existingEnvFrom;
        $changed = false;
        foreach ($envFromRefs as $wanted) {
            $key = "{$wanted['kind']}:{$wanted['name']}";
            if (isset($known[$key])) {
                continue;
            }
            // optional: true — a tool only ever touched on its plain-var
            // side (or only its secret side) must not fail to start because
            // the OTHER, never-created half of the pair doesn't exist yet.
            $merged[] = [$wanted['kind'] => ['name' => $wanted['name'], 'optional' => true]];
            $changed = true;
        }

        if (! $changed) {
            return new KubectlResult(true, 'unchanged');
        }

        // A strategic merge patch, keyed by the containers list's `name`
        // merge key, so this touches only the named container — a plain
        // JSON merge patch (`--type=merge`) has no way to address one
        // element of an array and would replace the whole `containers` list.
        return $this->run(
            ['patch', 'deployment', $deployment, '-n', $namespace, '--type=strategic', '--patch-file=/dev/stdin'],
            (string) json_encode([
                'spec' => ['template' => ['spec' => ['containers' => [
                    ['name' => $container, 'envFrom' => $merged],
                ]]]],
            ]),
        );
    }

    /**
     * Run a command in a pod (`deploy/<name>` picks one of its pods).
     *
     * @param  list<string>  $command
     */
    public function exec(string $namespace, string $target, array $command, ?string $stdin = null, ?string $container = null, ?int $timeoutSeconds = null): KubectlResult
    {
        return $this->run([
            'exec', ...($stdin !== null ? ['-i'] : []), '-n', $namespace, $target,
            ...($container !== null ? ['-c', $container] : []), '--', ...$command,
        ], $stdin, $timeoutSeconds);
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
    public function raw(array $args, ?string $stdin = null, ?int $timeoutSeconds = null): KubectlResult
    {
        return $this->run($args, $stdin, $timeoutSeconds);
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
