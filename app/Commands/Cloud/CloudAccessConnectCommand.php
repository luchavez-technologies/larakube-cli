<?php

namespace App\Commands\Cloud;

use App\Facades\State;
use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Give LaraKube Cloud read-only sight of a cluster: a ServiceAccount, with nothing of ours running
 * there. It is proven against the live API before it is returned, and removed if it can do more.
 */
class CloudAccessConnectCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    public const IDENTITY = 'larakube-cloud';

    public const OBSERVER = 'larakube-cloud-observer';

    public const SERVICE_ACCOUNT = 'system:serviceaccount:larakube-access:larakube-cloud';

    /** Each entry is [verb, resource, whether the identity must be allowed]. */
    public const PERMISSIONS = [
        ['list', 'nodes', true],
        ['list', 'pods', true],
        ['get', 'pods/log', true],
        ['list', 'events', true],
        ['list', 'nodes.metrics.k8s.io', true],
        ['get', 'secrets', false],
        ['list', 'secrets', false],
        ['create', 'pods/exec', false],
        ['create', 'pods', false],
        ['delete', 'pods', false],
        ['patch', 'deployments.apps', false],
        ['patch', 'nodes', false],
        ['create', 'clusterrolebindings.rbac.authorization.k8s.io', false],
        ['create', 'serviceaccounts/token', false],
    ];

    protected $signature = 'cloud:access:connect
        {--context= : The kube-context to connect}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Give LaraKube Cloud read-only access to a cluster (no Secrets, exec or changes)';

    /** @var array<string, mixed> */
    private array $result = [];

    public function handle(): int
    {
        $json = (bool) $this->flag('json');
        if ($json) {
            $this->enableJsonMode();
        }
        $stdout = State::stdout();

        $exit = $this->connect();

        if ($json) {
            // The grant sub-command points the JSON channel at its own buffer.
            State::setStdout($stdout);
            State::setJsonMode(true);
            $this->jsonOutput($exit === 0
                ? array_merge(['success' => true], $this->result, ['error' => null])
                : ['success' => false, 'error' => State::lastError() ?? 'Read access was not set up.']);
        }

        return $exit;
    }

    public static function observerManifest(): string
    {
        $observer = self::OBSERVER;
        $identity = self::IDENTITY;

        return <<<YAML
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: {$observer}
  labels:
    app.kubernetes.io/managed-by: larakube
rules:
- apiGroups: [""]
  resources: ["nodes"]
  verbs: ["get", "list", "watch"]
- apiGroups: ["metrics.k8s.io"]
  resources: ["nodes", "pods"]
  verbs: ["get", "list"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: {$observer}
  labels:
    app.kubernetes.io/managed-by: larakube
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: {$observer}
subjects:
- kind: ServiceAccount
  name: {$identity}
  namespace: larakube-access
YAML;
    }

    private function connect(): int
    {
        $context = (string) $this->option('context');
        if ($context === '') {
            $this->laraKubeError('--context is required.');

            return 1;
        }

        $directory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $buffer = new BufferedOutput;
        $exit = Artisan::call('cluster:grant', [
            '--read' => true,
            '--cluster' => true,
            '--name' => self::IDENTITY,
            '--context' => $context,
            '--output' => $directory->path('read.kubeconfig'),
            '--json' => true,
            '--no-interaction' => true,
        ], $buffer);
        $lines = preg_split('/\R/', trim($buffer->fetch())) ?: [];
        $grant = json_decode((string) end($lines), true);
        $directory->delete();

        if ($exit !== 0 || ! is_array($grant) || ($grant['success'] ?? false) !== true || empty($grant['kubeconfig'])) {
            State::setLastError('Could not create the read-only identity: '.($grant['error'] ?? 'unknown error'));

            return 1;
        }

        $cluster = Kubectl::forContext($context);

        if (! $cluster->apply(self::observerManifest())->ok) {
            $this->rollBack($context);
            State::setLastError('Could not add the node and metrics read role, so access was removed.');

            return 1;
        }

        // Not every cluster runs metrics-server; health still renders without CPU and memory.
        $hasMetrics = trim($cluster->raw(['api-resources', '--api-group=metrics.k8s.io', '-o', 'name'])->output) !== '';

        $wrong = [];
        foreach (self::PERMISSIONS as [$verb, $resource, $allowed]) {
            if (! $hasMetrics && str_contains($resource, 'metrics.k8s.io')) {
                continue;
            }

            $answer = trim($cluster->raw(['auth', 'can-i', $verb, $resource, '-A', '--as='.self::SERVICE_ACCOUNT])->output);
            if (($answer === 'yes') !== $allowed) {
                $wrong[] = "{$verb} {$resource}";
            }
        }

        if ($wrong !== []) {
            $this->rollBack($context);
            State::setLastError('Read access did not match what Cloud promises ('.implode(', ', $wrong).'), so it was removed.');

            return 1;
        }

        $this->registerSecret((string) $grant['kubeconfig']);
        $this->result = [
            'identity' => $grant['identity'] ?? null,
            'metrics' => $hasMetrics,
            'kubeconfig' => $grant['kubeconfig'],
            'can' => array_values(array_map(fn (array $p): string => "{$p[0]} {$p[1]}", array_filter(self::PERMISSIONS, fn (array $p): bool => $p[2]))),
            'cannot' => array_values(array_map(fn (array $p): string => "{$p[0]} {$p[1]}", array_filter(self::PERMISSIONS, fn (array $p): bool => ! $p[2]))),
        ];
        $this->laraKubeInfo('✅ LaraKube Cloud has verified read-only access.');

        return 0;
    }

    private function rollBack(string $context): void
    {
        Artisan::call('cloud:access:disconnect', ['--context' => $context, '--no-interaction' => true], new BufferedOutput);
    }
}
