<?php

namespace App\Commands\Cloud;

use App\Facades\State;
use App\Http\Integrations\Google\GoogleComputeConnector;
use App\Http\Integrations\Google\Requests\GetInstanceRequest;
use App\Http\Integrations\Google\Requests\SetInstanceMetadataRequest;
use App\Http\Integrations\Google\Requests\WaitZoneOperationRequest;
use App\Services\Cloud\GcpServiceAccountToken;
use App\Services\Kubectl;
use App\Support\SshPublicKey;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use LaravelZero\Framework\Commands\Command;
use Saloon\Http\Response;
use Throwable;

/**
 * Make a server's "larakube" SSH keys exactly the given list: a key left out is revoked. A Google Cloud
 * VM is changed through its metadata (Google's guest agent applies it; other metadata and other users'
 * keys are kept). Any other server is changed through its own cluster with --kubeconfig: a one-off Job
 * that rewrites only that user's authorized_keys. Safe to re-run.
 */
class CloudSshKeysSyncCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    private const SSH_USER = 'larakube';

    private const MAX_ATTEMPTS = 3;

    /** The Job's only access to the host: this one directory. */
    private const HOST_SSH_DIR = '/home/'.self::SSH_USER.'/.ssh';

    /** Pinned by digest: the Job runs with file-ownership capabilities on the host's .ssh directory. */
    public const SYNC_IMAGE = 'busybox:1.38.0@sha256:a46257e06c82ad85242d0d38077d0a52d9896389ca60a1a59d3463db586e5fa4';

    private const SYNC_NAMESPACE = 'kube-system';

    private const SYNC_TIMEOUT_SECONDS = 120;

    protected $signature = 'cloud:ssh-keys:sync
        {stack : The VM name}
        {--authorized-key=* : Public SSH key that should have access, repeatable. Keys left out are revoked}
        {--zone= : Google Cloud zone of the VM}
        {--gcp-project= : Google Cloud project ID}
        {--gcp-credentials= : Path to a service account JSON key (or set GOOGLE_OAUTH_ACCESS_TOKEN)}
        {--kubeconfig= : Any provider: a kubeconfig for the server\'s own cluster, to change the keys through it instead of Google Cloud metadata}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Set exactly which public SSH keys can log in to a server';

    /** @var array<string, mixed> */
    private array $result = [];

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $exit = $this->sync();
        } catch (Throwable $e) {
            $this->laraKubeError('Could not update SSH keys: '.$e->getMessage());
            $exit = 1;
        }

        if (State::isJsonMode()) {
            $this->jsonOutput($exit === 0
                ? array_merge(['success' => true], $this->result, ['error' => null])
                : ['success' => false, 'error' => State::lastError() ?? 'SSH keys were not updated.']);
        }

        return $exit;
    }

    /**
     * The new ssh-keys value: other users' lines untouched, this user's lines replaced.
     *
     * @param  list<string>  $keys
     */
    public static function mergeSshKeys(string $existing, array $keys): string
    {
        $others = array_filter(
            preg_split('/\R/', $existing) ?: [],
            fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), self::SSH_USER.':'),
        );

        return implode("\n", [...array_values($others), ...array_map(fn (string $key): string => self::SSH_USER.':'.$key, $keys)]);
    }

    private function sync(): int
    {
        if ((string) $this->option('kubeconfig') !== '') {
            return $this->syncThroughCluster();
        }

        $stack = (string) $this->argument('stack');
        $zone = (string) $this->option('zone');
        $project = (string) $this->option('gcp-project');

        if (preg_match('/^[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/', $stack) !== 1
            || preg_match('/^[a-z]+-[a-z]+[0-9]+-[a-z]$/', $zone) !== 1
            || preg_match('/^[a-z][a-z0-9:.-]{4,61}[a-z0-9]$/', $project) !== 1) {
            $this->laraKubeError('A valid VM name, --zone and --gcp-project are required.');

            return 1;
        }

        $keys = $this->resolveKeys();
        if ($keys === null) {
            return 1;
        }

        $compute = new GoogleComputeConnector($this->accessToken());

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $instance = $compute->send(new GetInstanceRequest($project, $zone, $stack));
            if (! $instance->successful()) {
                $this->laraKubeError($this->googleError('read the VM', $instance));

                return 1;
            }

            $items = $instance->json('metadata.items') ?? [];
            $current = collect($items)->firstWhere('key', 'ssh-keys')['value'] ?? '';
            $desired = self::mergeSshKeys((string) $current, $keys);

            if ($this->sameKeys((string) $current, $keys)) {
                $this->result = ['stackName' => $stack, 'keys' => count($keys), 'changed' => false];
                $this->laraKubeInfo('SSH keys are already up to date.');

                return 0;
            }

            $updated = [...array_values(array_filter($items, fn (array $item): bool => $item['key'] !== 'ssh-keys')), ['key' => 'ssh-keys', 'value' => $desired]];
            $response = $compute->send(new SetInstanceMetadataRequest($project, $zone, $stack, (string) $instance->json('metadata.fingerprint'), $updated));

            // Someone else changed the metadata since it was read, so read it again.
            if ($response->status() === 412) {
                continue;
            }

            if (! $response->successful()) {
                $this->laraKubeError($this->googleError('update the VM metadata', $response));

                return 1;
            }

            if (! $this->waitFor($compute, $project, $zone, (string) $response->json('name'))) {
                return 1;
            }

            $this->result = ['stackName' => $stack, 'keys' => count($keys), 'changed' => true];
            $this->laraKubeInfo('✅ SSH keys updated. Google\'s guest agent applies them on the VM shortly.');

            return 0;
        }

        $this->laraKubeError('The VM metadata kept changing while updating it. Try again.');

        return 1;
    }

    /** @return list<string>|null */
    private function resolveKeys(): ?array
    {
        $keys = [];
        foreach ((array) $this->option('authorized-key') as $raw) {
            $key = SshPublicKey::normalize((string) $raw);
            if ($key === null) {
                $this->laraKubeError('--authorized-key must be a single OpenSSH public key (ed25519, ECDSA or RSA). Private keys are rejected.');

                return null;
            }
            $keys[$key] = $key;
        }

        if ($keys === []) {
            $this->laraKubeError('At least one --authorized-key is required, so nobody is locked out of the server.');

            return null;
        }

        return array_values($keys);
    }

    private function accessToken(): string
    {
        $token = getenv('GOOGLE_OAUTH_ACCESS_TOKEN') ?: (new GcpServiceAccountToken)->mint((string) $this->option('gcp-credentials'));
        $this->registerSecret($token);

        return $token;
    }

    /** @return list<string> */
    private function currentKeys(string $metadata): array
    {
        $keys = [];
        foreach (preg_split('/\R/', $metadata) ?: [] as $line) {
            if (str_starts_with(trim($line), self::SSH_USER.':')) {
                $keys[] = substr(trim($line), strlen(self::SSH_USER) + 1);
            }
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function sameKeys(string $metadata, array $keys): bool
    {
        $current = $this->currentKeys($metadata);
        sort($current);
        sort($keys);

        return $current === $keys;
    }

    private function waitFor(GoogleComputeConnector $compute, string $project, string $zone, string $operation): bool
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $compute->send(new WaitZoneOperationRequest($project, $zone, $operation));

            if (! $response->successful()) {
                $this->laraKubeError($this->googleError('confirm the update', $response));

                return false;
            }

            if ($response->json('status') === 'DONE') {
                if ($response->json('error') !== null) {
                    $this->laraKubeError('Google Cloud could not apply the SSH key update: '.($response->json('error.errors.0.message') ?? 'unknown error'));

                    return false;
                }

                return true;
            }
        }

        $this->laraKubeError('Google Cloud did not finish the SSH key update in time. Run this again to check.');

        return false;
    }

    private function googleError(string $action, Response $response): string
    {
        return "Could not {$action} (HTTP {$response->status()}): ".($response->json('error.message') ?? 'no details from Google Cloud');
    }

    /**
     * Any provider: rewrite the larakube user's authorized_keys through the server's own cluster. The
     * caller's kubeconfig already has full control of that cluster, so this adds no new power; the Job
     * is still kept narrow: one mounted directory, no privilege escalation, a read-only image filesystem,
     * only the capabilities needed to write a file it does not own, and the keys as data, never as shell.
     */
    private function syncThroughCluster(): int
    {
        $stack = (string) $this->argument('stack');
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $stack) !== 1) {
            $this->laraKubeError('A valid server name is required.');

            return 1;
        }

        $kubeconfig = (string) $this->option('kubeconfig');
        if (! is_file($kubeconfig)) {
            $this->laraKubeError('The --kubeconfig file does not exist.');

            return 1;
        }

        $keys = $this->resolveKeys();
        if ($keys === null) {
            return 1;
        }

        $kubectl = Kubectl::forKubeconfig($kubeconfig);
        $name = 'larakube-ssh-keys-'.substr(hash('sha256', $stack.implode("\n", $keys).microtime()), 0, 10);
        $namespace = self::SYNC_NAMESPACE;

        try {
            $applied = $kubectl->raw(['apply', '-f', '-'], (string) json_encode(self::syncManifest($name, $namespace, $keys), JSON_UNESCAPED_SLASHES));
            if (! $applied->ok) {
                $this->laraKubeError('Could not start the key update on the server: '.trim($applied->error ?: $applied->output));

                return 1;
            }

            $done = $kubectl->raw(
                ['wait', '--for=condition=complete', "job/{$name}", '-n', $namespace, '--timeout='.self::SYNC_TIMEOUT_SECONDS.'s'],
                timeoutSeconds: self::SYNC_TIMEOUT_SECONDS + 30,
            );
            if (! $done->ok) {
                $logs = $kubectl->raw(['logs', "job/{$name}", '-n', $namespace, '--tail=20']);
                $this->laraKubeError('The server did not apply the SSH keys. '.trim($logs->output ?: $done->error ?: $done->output));

                return 1;
            }
        } finally {
            $kubectl->raw(['delete', "job/{$name}", "configmap/{$name}", '-n', $namespace, '--ignore-not-found', '--wait=false']);
        }

        $this->result = ['stackName' => $stack, 'keys' => count($keys), 'changed' => true];
        $this->laraKubeInfo('✅ SSH keys updated on the server.');

        return 0;
    }

    /**
     * The ConfigMap holding the keys and the Job that installs them.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function syncManifest(string $name, string $namespace, array $keys): array
    {
        $labels = ['app.kubernetes.io/managed-by' => 'larakube', 'larakube.io/purpose' => 'ssh-keys'];
        // Staged beside the target and moved into place, so a reader never sees half a file. The owner is
        // whoever owns the directory (the larakube user), so the result is exactly what sshd expects.
        $script = <<<'SH'
set -eu
dir=/host-ssh
owner=$(stat -c %u:%g "$dir")
staged=$(mktemp "$dir/authorized_keys.XXXXXX")
trap 'rm -f "$staged"' EXIT
cp /keys/authorized_keys "$staged"
chown "$owner" "$staged"
chmod 600 "$staged"
mv -f "$staged" "$dir/authorized_keys"
while IFS= read -r key; do
  [ -z "$key" ] && continue
  grep -qxF -- "$key" "$dir/authorized_keys"
done < /keys/authorized_keys
echo "SSH keys installed."
SH;

        return [
            'apiVersion' => 'v1',
            'kind' => 'List',
            'items' => [
                [
                    'apiVersion' => 'v1',
                    'kind' => 'ConfigMap',
                    'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
                    'data' => ['authorized_keys' => implode("\n", $keys)."\n"],
                ],
                [
                    'apiVersion' => 'batch/v1',
                    'kind' => 'Job',
                    'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
                    'spec' => [
                        'backoffLimit' => 0,
                        'activeDeadlineSeconds' => self::SYNC_TIMEOUT_SECONDS,
                        'ttlSecondsAfterFinished' => 300,
                        'template' => [
                            'metadata' => ['labels' => $labels],
                            'spec' => [
                                'restartPolicy' => 'Never',
                                'automountServiceAccountToken' => false,
                                // The server's node; tolerate its control-plane taint.
                                'nodeSelector' => ['node-role.kubernetes.io/control-plane' => 'true'],
                                'tolerations' => [['operator' => 'Exists']],
                                'containers' => [[
                                    'name' => 'ssh-keys',
                                    'image' => self::SYNC_IMAGE,
                                    'command' => ['sh', '-c', $script],
                                    'securityContext' => [
                                        'privileged' => false,
                                        'allowPrivilegeEscalation' => false,
                                        'readOnlyRootFilesystem' => true,
                                        'runAsUser' => 0,
                                        'capabilities' => ['drop' => ['ALL'], 'add' => ['CHOWN', 'DAC_OVERRIDE', 'FOWNER']],
                                        'seccompProfile' => ['type' => 'RuntimeDefault'],
                                    ],
                                    'volumeMounts' => [
                                        ['name' => 'host-ssh', 'mountPath' => '/host-ssh'],
                                        ['name' => 'keys', 'mountPath' => '/keys', 'readOnly' => true],
                                    ],
                                ]],
                                'volumes' => [
                                    ['name' => 'host-ssh', 'hostPath' => ['path' => self::HOST_SSH_DIR, 'type' => 'Directory']],
                                    ['name' => 'keys', 'configMap' => ['name' => $name]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
