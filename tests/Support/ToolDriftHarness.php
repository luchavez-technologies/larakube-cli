<?php

namespace Tests\Support;

use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;
use Termwind\Termwind;
use Throwable;

/**
 * Drives a Cluster Tool's real `:init` twice (instances A and B) and its
 * `:remove --purge` once (A) against one in-memory fake cluster, and reports
 * every way the two commands disagree about names.
 *
 * Nothing here knows a tool's names: it only compares what `:init` created
 * with what `:remove` deleted, and the Commons tenants each side touched with
 * the ones ToolInstance predicts. That is what makes it a drift detector.
 */
final class ToolDriftHarness
{
    /** @var list<array<string, mixed>> */
    private array $toolRows = [];

    /** @var array<string, array<string, mixed>> */
    private array $tenants = [];

    /** @var array<string, ResourceRef> */
    private array $live = [];

    /** @var array<string, ResourceRef> */
    private array $created = [];

    /** @var array<string, ResourceRef> */
    private array $deleted = [];

    private function __construct(private readonly ClusterTool $tool) {}

    /**
     * @return array{harnessed: bool, reason: ?string, problems: list<string>}
     */
    public static function check(ClusterTool $tool): array
    {
        return (new self($tool))->run();
    }

    /**
     * @return array{harnessed: bool, reason: ?string, problems: list<string>}
     */
    private function run(): array
    {
        $this->fakeCluster();

        $a = $this->install('a.example.com');
        if (is_string($a)) {
            return ['harnessed' => false, 'reason' => "init A: {$a}", 'problems' => []];
        }
        [$createdA, $tenantsA, $hostA] = $a;

        $b = $this->install('b.example.com');
        if (is_string($b)) {
            return ['harnessed' => false, 'reason' => "init B: {$b}", 'problems' => []];
        }
        [$createdB, $tenantsB, $hostB] = $b;

        $this->deleted = [];
        $tenantsBefore = array_keys($this->tenants);
        $removed = $this->artisan($this->tool->removeCommand(), ['--domain' => $hostA, '--purge' => true, '--force' => true]);
        if ($removed !== null && str_contains($removed, 'does not support multiple instances')) {
            $problems = ['remove refuses --domain: instances can\'t be removed one at a time',
                ...$this->allocationProblems($tenantsA, $tenantsB, $hostA, $hostB)];
            sort($problems);

            return ['harnessed' => true, 'reason' => null, 'problems' => $problems];
        }
        if ($removed !== null) {
            return ['harnessed' => false, 'reason' => "remove A: {$removed}", 'problems' => []];
        }
        $releasedTenants = array_values(array_diff($tenantsBefore, array_keys($this->tenants)));

        $shared = array_intersect_key($createdA, $createdB);
        $ownA = array_diff_key($createdA, $shared);
        $ownB = array_diff_key($createdB, $shared);

        $problems = [];
        foreach (array_diff_key($ownA, $this->deleted) as $key => $_) {
            $problems[] = "left behind: {$key}";
        }
        foreach (array_intersect_key($this->deleted, $ownB) as $key => $_) {
            $problems[] = "deleted B's: {$key}";
        }
        foreach (array_intersect_key($this->deleted, $shared) as $key => $_) {
            $problems[] = "deleted shared: {$key}";
        }

        array_push($problems, ...$this->allocationProblems($tenantsA, $tenantsB, $hostA, $hostB));
        foreach (array_diff($tenantsA, $releasedTenants) as $tenant) {
            $problems[] = "tenant not freed by purge: {$tenant}";
        }
        foreach (array_intersect($releasedTenants, array_merge($tenantsB, $this->expectedTenants($hostB))) as $tenant) {
            $problems[] = "purge freed B's tenant: {$tenant}";
        }

        sort($problems);

        return ['harnessed' => true, 'reason' => null, 'problems' => array_values(array_unique($problems))];
    }

    /**
     * @return array{0: array<string, ResourceRef>, 1: list<string>, 2: string}|string
     */
    private function install(string $domain): array|string
    {
        $this->created = [];
        $rowsBefore = count($this->toolRows);
        $tenantsBefore = array_keys($this->tenants);

        $failure = $this->artisan($this->tool->initCommand(), ['--domain' => $domain, '--force' => true]);
        if ($failure !== null) {
            return $failure;
        }

        $row = array_slice($this->toolRows, $rowsBefore)[0] ?? null;
        $host = (string) ($row['host'] ?? '');
        if ($host === '') {
            return 'did not register an instance';
        }

        return [$this->created, array_values(array_diff(array_keys($this->tenants), $tenantsBefore)), $host];
    }

    /**
     * What `:init` allocated versus what ToolInstance says each instance owns.
     *
     * @param  list<string>  $tenantsA
     * @param  list<string>  $tenantsB
     * @return list<string>
     */
    private function allocationProblems(array $tenantsA, array $tenantsB, string $hostA, string $hostB): array
    {
        $problems = [];
        foreach ([[$tenantsA, $hostA], [$tenantsB, $hostB]] as [$allocated, $host]) {
            foreach (array_diff($allocated, $this->expectedTenants($host)) as $tenant) {
                $problems[] = "tenant not derived from ToolInstance: {$tenant}";
            }
        }
        if ($tenantsB === [] && $tenantsA !== []) {
            $problems[] = 'instances share Commons tenants: B allocated none of its own';
        }

        return $problems;
    }

    /** @return list<string> */
    private function expectedTenants(string $host): array
    {
        $instance = ToolInstance::forHost($this->tool, $host);

        return array_merge($instance->commonsDatabases(), $instance->commonsRedisTenants(), $instance->commonsBuckets());
    }

    /** Null on success, else a one-line reason. */
    private function artisan(string $command, array $options): ?string
    {
        $definition = Artisan::all()[$command]->getDefinition();
        $parameters = ['environment' => 'production', '--context' => 'drift-ctx', '--no-interaction' => true];

        foreach ($options + ['--admin-email' => 'admin@example.com', '--email' => 'admin@example.com'] as $name => $value) {
            if ($definition->hasOption(ltrim($name, '-'))) {
                $parameters[$name] = $value;
            }
        }

        $buffer = new BufferedOutput;
        Termwind::renderUsing($buffer);

        try {
            $exit = Artisan::call($command, $parameters, $buffer);
        } catch (Throwable $e) {
            return class_basename($e).': '.strtok($e->getMessage(), "\n");
        } finally {
            Termwind::renderUsing(null);
        }

        if ($exit === 0) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) preg_replace('/\e\[[\d;]*m/', '', $buffer->fetch()))),
            fn (string $line) => $line !== '' && ! str_contains($line, 'loading...') && ! preg_match('/^[█╗╔╝╚═║ ]+$/u', $line)));

        return "exit {$exit}: ".implode(' | ', array_slice($lines, -2));
    }

    private function fakeCluster(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        MockClient::global(['*' => MockResponse::make(['success' => true, 'result' => []])]);

        Process::fake(['*' => fn (PendingProcess $process) => $this->answer($process)]);
    }

    private function answer(PendingProcess $process)
    {
        $command = (string) (is_array($process->command) ? implode(' ', $process->command) : $process->command);

        // App\Services\Kubectl quotes every argument; read it as plain words.
        if (preg_match("/ kubectl(?: --context '(?:[^']|'\\\\'')*')? ('.*)$/s", $command, $m) === 1) {
            preg_match_all("/'((?:[^']|'\\\\'')*)'/", $m[1], $tokens);
            $command = 'kubectl '.implode(' ', array_map(fn (string $t) => str_replace("'\\''", "'", $t), $tokens[1]));

            if (preg_match('#^kubectl get ([a-z]+)/(\S+) -n (\S+) -o (name|json)#', $command, $g) === 1) {
                $ref = new ResourceRef($g[1], $g[2], $g[3]);
                if (! isset($this->live[strtolower($ref->key())])) {
                    return Process::result(output: '');
                }

                return Process::result(output: $g[4] === 'name'
                    ? "{$g[1]}/{$g[2]}"
                    : (string) json_encode(['kind' => $g[1], 'metadata' => ['name' => $g[2], 'namespace' => $g[3]]]));
            }
        }

        if (str_contains($command, 'larakube-tools-registry')) {
            if (preg_match('/registry\\.json=([^ ]+)/', $command, $m) === 1 && str_contains($command, 'create secret')) {
                $this->toolRows = json_decode((string) file_get_contents(trim($m[1], "'\"")), true) ?: [];

                return Process::result(output: 'configured');
            }

            return Process::result(output: base64_encode((string) json_encode($this->toolRows)));
        }

        if (str_contains($command, 'plex-registry')) {
            if (str_contains($command, 'create configmap') && preg_match('/registry\\.json=([^ ]+)/', $command, $m) === 1) {
                $this->tenants = json_decode((string) file_get_contents(trim($m[1], "'\"")), true)['tenants'] ?? [];

                return Process::result(output: 'configured');
            }

            return Process::result(output: (string) json_encode(['tenants' => $this->tenants]));
        }

        // Commons admin credentials exist on any cluster with Plex Commons.
        if (str_contains($command, 'get secret plex-admin')) {
            return Process::result(output: base64_encode('drift-credential'));
        }

        // Reads of a key's value: nothing stored yet, so every tool generates.
        if (preg_match('/ get secret \S+ .*-o (jsonpath|json|yaml)/', $command) === 1) {
            return Process::result(output: '');
        }

        if (str_contains($command, '{.spec.clusterIP}')) {
            return Process::result(output: '10.43.0.99');
        }

        if (str_contains($command, 'plex-commons')) {
            return Process::result(output: (string) json_encode(['version' => 1, 'services' => [
                'postgres' => ['enabled' => true], 'redis' => ['enabled' => true], 'seaweedfs' => ['enabled' => true],
                'headless-shell' => ['enabled' => true],
            ]]));
        }

        if (preg_match('/ apply -f (?:\'([^\']+)\'|("[^"]+")|(\S+))/', $command, $m) === 1) {
            $path = trim($m[1] ?: $m[2] ?: $m[3], "'\"");
            $yaml = $path === '-' ? (string) $process->input : (is_file($path) ? (string) file_get_contents($path) : '');
            if (! str_contains($command, 'create secret') && ! str_contains($command, 'create configmap')) {
                $this->recordManifest($yaml);
            }
        }

        if (preg_match('/create (secret generic|secret tls|configmap) (\S+).*? -n (\S+)/', $command, $m) === 1) {
            $this->record(new ResourceRef(str_starts_with($m[1], 'secret') ? 'Secret' : 'ConfigMap', trim($m[2], "'"), trim($m[3], "'")));
        }

        if (preg_match('/ delete (.+?) -n (\S+)/', $command, $m) === 1) {
            $this->recordDeletes($m[1], trim($m[2], "'"));
        }

        if (preg_match('/ get (deployment|deploy|secret|configmap|pvc|ingress|service|svc)\s+([a-z0-9][a-z0-9.-]*)\s+-n\s+(\S+)/', $command, $m) === 1) {
            $kind = ['deploy' => 'deployment', 'svc' => 'service', 'pvc' => 'persistentvolumeclaim'][$m[1]] ?? $m[1];
            $ref = new ResourceRef($kind, $m[2], trim($m[3], "'"));
            $exists = isset($this->live[strtolower($ref->key())]);

            return Process::result(output: $exists ? "{$kind}/{$m[2]}" : '', exitCode: $exists || str_contains($command, '--ignore-not-found') ? 0 : 1);
        }

        return Process::result(output: '');
    }

    private function recordManifest(string $yaml): void
    {
        foreach (preg_split('/^---\s*$/m', $yaml) ?: [] as $document) {
            try {
                $doc = Yaml::parse($document);
            } catch (Throwable) {
                continue;
            }

            $kind = is_array($doc) ? ($doc['kind'] ?? null) : null;
            $name = is_array($doc) ? ($doc['metadata']['name'] ?? null) : null;
            if (! is_string($kind) || ! is_string($name) || in_array($kind, ['Namespace', 'CustomResourceDefinition', 'ClusterRole', 'ClusterRoleBinding'], true)) {
                continue;
            }

            $this->record(new ResourceRef($kind, $name, (string) ($doc['metadata']['namespace'] ?? $this->tool->namespace())));
        }
    }

    private function record(ResourceRef $ref): void
    {
        $key = strtolower($ref->key());
        $this->created[$key] = $ref;
        $this->live[$key] = $ref;
    }

    /** `deployment/a service/b` or `deployment,svc a b`. */
    private function recordDeletes(string $targets, string $namespace): void
    {
        $alias = ['deploy' => 'deployment', 'deployments' => 'deployment', 'svc' => 'service', 'cm' => 'configmap', 'pvc' => 'persistentvolumeclaim', 'ing' => 'ingress', 'secrets' => 'secret'];
        $tokens = array_values(array_filter(preg_split('/\s+/', $targets) ?: [], fn ($t) => $t !== '' && ! str_starts_with($t, '-')));

        $refs = [];
        if ($tokens !== [] && ! str_contains($tokens[0], '/')) {
            foreach (explode(',', $tokens[0]) as $kind) {
                foreach (array_slice($tokens, 1) as $name) {
                    $refs[] = [$alias[$kind] ?? $kind, $name];
                }
            }
        } else {
            foreach ($tokens as $token) {
                if (str_contains($token, '/')) {
                    [$kind, $name] = explode('/', $token, 2);
                    $refs[] = [$alias[$kind] ?? $kind, $name];
                }
            }
        }

        foreach ($refs as [$kind, $name]) {
            $key = strtolower("{$namespace}/{$kind}/{$name}");
            $this->deleted[$key] = new ResourceRef($kind, $name, $namespace);
            unset($this->live[$key]);
        }
    }
}
