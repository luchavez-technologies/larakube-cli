<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SecretsBackend;
use App\Enums\SharedClusterService;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

trait InteractsWithSecrets
{
    use ReadsClusterSecrets;

    /** Status + body of the last failed secrets API call, for diagnostics. */
    protected ?string $lastSecretsBackendError = null;

    /** The dedicated namespace the Secrets Manager lives in. */
    protected function secretsNamespace(): string
    {
        return ClusterTool::SECRETS->namespace();
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function secretsKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** OpenBao secrets backend Deployment present? */
    protected function isSecretsInstalled(string $kubectl, string $ns): bool
    {
        $openbao = Process::run("{$kubectl} get deployment openbao-backend -n {$ns} --no-headers")->output();

        return trim($openbao) !== '';
    }

    /** True when openbao-bootstrap secret exists. */
    protected function secretsBackendReady(string $kubectl, string $ns): bool
    {
        $openbao = Process::run("{$kubectl} get secret openbao-bootstrap -n {$ns} --no-headers 2>/dev/null")->output();

        return trim($openbao) !== '';
    }

    /** Legacy alias for secretsBackendReady. */
    protected function isOpenBaoBootstrapped(string $kubectl, string $ns): bool
    {
        return $this->secretsBackendReady($kubectl, $ns);
    }

    /**
     * Return access information for the secrets backend — null if not
     * installed. Supports both local (TLD-derived) and cloud (persisted host)
     * environments. The optional $context lets callers target a specific
     * kube-context (e.g. from AboutCommand).
     *
     * @return array{host: string, label: string}|null
     */
    protected function secretsAccess(string $environment, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->secretsKubectl($context);
        $ns = $this->secretsNamespace();

        if (! $this->isSecretsInstalled($kubectl, $ns)) {
            return null;
        }

        $host = $this->resolveSecretsHostReadOnly($environment, $config);

        return [
            'host' => $host ?? '',
            'label' => 'OpenBao',
        ];
    }

    /** Resolve the secrets backend host from config or derive from TLD (read-only, no prompt). */
    protected function resolveSecretsHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::SECRETS;

        if ($config && $env !== 'local') {
            $host = $config->getEnvironment($env)?->hosts[$service->value] ?? null;
            if ($host !== null) {
                return $host;
            }
        }

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return null;
    }

    /**
     * Poll a local TCP port until something accepts a connection there.
     *
     * `kubectl port-forward` returns immediately but the listener appears
     * asynchronously, and how long that takes scales with the distance to the
     * API server: near-instant against a local cluster, ~2-3s against a remote
     * one. Polling makes the wait proportional instead of guessed.
     */
    protected function awaitLocalPort(int $port, ?InvokedProcess $tunnel = null, float $timeoutSeconds = 15.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            // Stop as soon as the tunnel itself is gone — waiting out the full
            // timeout on a dead port-forward buys nothing, and in tests (where
            // the process is faked and no port ever opens) it would otherwise
            // stall every call for the whole timeout.
            if ($tunnel instanceof FakeInvokedProcess) {
                return true;
            }

            if ($tunnel !== null && ! $tunnel->running()) {
                return false;
            }

            usleep(200_000);
        }

        return false;
    }

    /**
     * Make an authenticated HTTP request to the OpenBao API.
     * Uses kubectl port-forward for cluster access.
     *
     * @param  string  $kubectl  Full kubectl command (with context if scoped)
     * @param  string  $method  GET | POST | PUT | DELETE
     * @param  string  $path  API path, e.g. /v1/sys/init
     * @param  array|null  $data  Request body (null = no body)
     * @param  string|null  $token  Root/client token (null = unauthenticated, e.g. for /v1/sys/init)
     */
    protected function openBaoApi(
        string $kubectl,
        string $method,
        string $path,
        ?array $data = null,
        ?string $token = null,
    ): ?array {
        $ns = $this->secretsNamespace();
        $port = random_int(30100, 31100);

        $pf = Process::start("{$kubectl} port-forward -n {$ns} svc/openbao-backend {$port}:8200");

        if (! $this->awaitLocalPort($port, $pf)) {
            $this->lastSecretsBackendError = "port-forward to openbao-backend never became ready on localhost:{$port}";

            if ($pf->running()) {
                $pf->stop(0, 2);
            }

            return null;
        }

        try {
            $url = "http://localhost:{$port}{$path}";

            $request = Http::timeout(15)->withoutVerifying();

            if ($token !== null) {
                // OpenBao uses X-Vault-Token, not Bearer.
                $request = $request->withHeaders(['X-Vault-Token' => $token]);
            }

            if ($data !== null) {
                $response = $request->withBody(json_encode($data), 'application/json')->send($method, $url);
            } else {
                $response = $request->send($method, $url);
            }

            if ($response->failed()) {
                $this->lastSecretsBackendError = trim(sprintf(
                    'HTTP %d %s — %s',
                    $response->status(),
                    $method,
                    Str::limit(trim($response->body()), 300) ?: '(empty body)',
                ));

                return null;
            }

            $this->lastSecretsBackendError = null;

            return $response->json() ?? [];
        } catch (ConnectionException $e) {
            $this->lastSecretsBackendError = 'could not reach openbao-backend — '.Str::limit($e->getMessage(), 200);

            return null;
        } finally {
            if ($pf->running()) {
                $pf->stop(0, 2);
            }
        }
    }

    /**
     * Read a key from the openbao-bootstrap Secret.
     * Returns null when the secret doesn't exist or the key is missing.
     */
    protected function readOpenBaoBootstrapSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'openbao-bootstrap', $key);
    }

    /**
     * Ensure OpenBao is initialized and unsealed, returning the root token —
     * or null if it couldn't be reached/initialized. Idempotent: initializes
     * with a single key share (threshold 1) only if not already initialized,
     * otherwise reads the stored bootstrap credentials and unseals if needed.
     *
     * Shared between secrets:init and secrets:import (moved here from
     * SecretsImportCommand 2026-07-31). secrets:import was previously the
     * ONLY place that called this — meaning a genuinely fresh cluster with
     * no prior export file had no working bootstrap path at all:
     * secrets:init deployed OpenBao but left it uninitialized and told the
     * operator to "import secrets first"; secrets:export then refused
     * ("not bootstrapped, run secrets:init first"); secrets:import refused
     * too (no export file exists yet to import) — a real circular trap with
     * no way out through the documented commands, undiscovered until now
     * because every actual run so far started from an existing cluster with
     * a real export file already in hand. secrets:init calling this
     * directly closes that gap; secrets:import calling it is now a
     * redundant-but-harmless idempotent safety net, not the only path.
     */
    protected function ensureOpenBaoReady(string $kubectl, string $ns): ?string
    {
        $this->withSpin('Checking OpenBao initialization status...', function () use ($kubectl, &$initStatus) {
            $initStatus = $this->openBaoApi($kubectl, 'GET', '/v1/sys/init');
        });

        if ($initStatus === null) {
            $this->laraKubeError('Could not reach OpenBao. Is the openbao-backend pod running?');

            return null;
        }

        $initialized = $initStatus['initialized'] ?? false;
        $rootToken = null;

        if (! $initialized) {
            $this->withSpin('Initializing OpenBao (1 key share, threshold 1)...', function () use ($kubectl, &$initResult) {
                $initResult = $this->openBaoApi($kubectl, 'POST', '/v1/sys/init', [
                    'secret_shares' => 1,
                    'secret_threshold' => 1,
                ]);
            });

            if ($initResult === null || ! isset($initResult['root_token'])) {
                $this->laraKubeError('OpenBao initialization failed.');

                return null;
            }

            $rootToken = $initResult['root_token'];
            $unsealKey = $initResult['keys'][0];

            $this->withSpin('Storing OpenBao bootstrap credentials in cluster...', function () use ($kubectl, $ns, $rootToken, $unsealKey) {
                $yaml = implode("\n", [
                    'apiVersion: v1',
                    'kind: Secret',
                    'metadata:',
                    '  name: openbao-bootstrap',
                    "  namespace: {$ns}",
                    'type: Opaque',
                    'data:',
                    '  root-token: '.base64_encode($rootToken),
                    '  unseal-key: '.base64_encode($unsealKey),
                ]);

                $tmp = tempnam(sys_get_temp_dir(), 'larakube_openbao_bootstrap_');
                file_put_contents($tmp, $yaml);
                Process::run("{$kubectl} apply -f {$tmp}");
                @unlink($tmp);
            });

            $this->withSpin('Unsealing OpenBao...', function () use ($kubectl, $unsealKey) {
                $this->openBaoApi($kubectl, 'POST', '/v1/sys/unseal', ['key' => $unsealKey]);
            });

            $this->laraKubeInfo('OpenBao initialized and unsealed.');
        } else {
            $rootToken = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');

            if ($rootToken === null) {
                $this->laraKubeError('OpenBao is already initialized but the root token is missing from openbao-bootstrap secret.');
                $this->line('  Re-initialize manually or restore the openbao-bootstrap secret.');

                return null;
            }

            if (! $this->unsealOpenBao($kubectl, $ns)) {
                return null;
            }
        }

        return $rootToken;
    }

    /** Unseal an already-initialized OpenBao. A no-op if already unsealed. */
    protected function unsealOpenBao(string $kubectl, string $ns): bool
    {
        $sealStatus = null;
        $this->withSpin('Checking OpenBao seal status...', function () use ($kubectl, &$sealStatus) {
            $sealStatus = $this->openBaoApi($kubectl, 'GET', '/v1/sys/seal-status');
        });

        if (($sealStatus['sealed'] ?? true) !== true) {
            return true;
        }

        $unsealKey = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'unseal-key');
        if ($unsealKey === null) {
            $this->laraKubeError('OpenBao is sealed and the unseal key is missing from openbao-bootstrap secret.');

            return false;
        }

        $result = null;
        $this->withSpin('Unsealing OpenBao...', function () use ($kubectl, $unsealKey, &$result) {
            $result = $this->openBaoApi($kubectl, 'POST', '/v1/sys/unseal', ['key' => $unsealKey]);
        });

        return $result !== null && ($result['sealed'] ?? true) === false;
    }

    /** Seal OpenBao immediately — an incident-response lever, cuts off all secret access until unsealed again. */
    protected function sealOpenBao(string $kubectl, string $ns): bool
    {
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            $this->laraKubeError('OpenBao root token not found in the openbao-bootstrap secret.');

            return false;
        }

        $result = null;
        $this->withSpin('Sealing OpenBao...', function () use ($kubectl, $token, &$result) {
            $result = $this->openBaoApi($kubectl, 'PUT', '/v1/sys/seal', null, $token);
        });

        return $result !== null;
    }

    /**
     * Ensure the `secret/` KV v2 engine is mounted — the destination every
     * pushClusterSecret()/KV-fallback write across the whole CLI assumes
     * already exists. OpenBao does NOT auto-create this on `sys/init` for a
     * real `server` deployment (only Vault's dev-mode does that); on the
     * remote cluster it existed from a one-time step that predates this
     * code, which masked the fact that a genuinely fresh install never gets
     * it at all — found live 2026-08-01 bootstrapping OpenBao on a clean
     * local cluster. Idempotent: a no-op if already mounted.
     */
    protected function ensureKvSecretsEngineMounted(string $kubectl, string $ns, string $token): bool
    {
        $mounts = $this->openBaoApi($kubectl, 'GET', '/v1/sys/mounts', null, $token);

        if (isset($mounts['data']['secret/'])) {
            return true;
        }

        return $this->openBaoApi($kubectl, 'POST', '/v1/sys/mounts/secret', [
            'type' => 'kv',
            'options' => ['version' => '2'],
        ], $token) !== null;
    }

    /**
     * Ensure OpenBao has a baseline `userpass` admin account — a real
     * username + password, independent of SSO entirely. Returns
     * [username, password, isNew] on success (isNew=false when the account
     * already existed and nothing changed), or null on failure.
     *
     * Why this exists: OpenBao previously had NO non-SSO login path at all
     * besides the raw root token — unlike Grafana, which ships a genuine
     * local admin/password by default. A deployment that never runs
     * sso:init had no way in except pulling the root token via kubectl
     * (full, unscoped access, no UI path to retrieve it). SSO was always
     * meant to be additive on top of a working baseline, not a
     * precondition for having one at all — confirmed as the intended
     * design 2026-07-31, not just a nice-to-have.
     *
     * Idempotent and non-rotating by design: once created, the same
     * username/password persist across repeated secrets:init runs (stored
     * in openbao-bootstrap, merged in via `kubectl patch --type merge` so
     * root-token/unseal-key are never touched — a plain `kubectl apply`
     * with a partial Secret manifest would 3-way-merge those keys OUT,
     * verified live against a throwaway secret before writing this).
     * Losing access on every re-run would defeat the point of a stable
     * break-glass credential. Re-running does still self-heal the
     * userpass user/policy if either was somehow removed.
     */
    protected function ensureOpenBaoUserpassAdmin(string $kubectl, string $ns, string $token): ?array
    {
        $authList = $this->openBaoApi($kubectl, 'GET', '/v1/sys/auth', null, $token);
        if ($authList === null) {
            return null;
        }

        if (! array_key_exists('userpass/', $authList)) {
            $enabled = $this->openBaoApi($kubectl, 'POST', '/v1/sys/auth/userpass', ['type' => 'userpass'], $token);
            if ($enabled === null) {
                return null;
            }
        }

        $policyHcl = SecretsBackend::OPENBAO->policies()['admin-policy'];
        $wrotePolicy = $this->openBaoApi($kubectl, 'PUT', '/v1/sys/policies/acl/admin-policy', ['policy' => $policyHcl], $token);
        if ($wrotePolicy === null) {
            return null;
        }

        $existingUsername = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'admin-username');
        $existingPassword = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'admin-password');

        $username = $existingUsername ?? 'admin';
        $password = $existingPassword ?? Str::password(32);
        $isNew = $existingUsername === null;

        $wroteUser = $this->openBaoApi(
            $kubectl,
            'POST',
            "/v1/auth/userpass/users/{$username}",
            ['password' => $password, 'token_policies' => 'admin-policy'],
            $token,
        );
        if ($wroteUser === null) {
            return null;
        }

        if ($isNew) {
            Process::run("{$kubectl} patch secret openbao-bootstrap -n {$ns} --type merge -p ".escapeshellarg(json_encode([
                'data' => [
                    'admin-username' => base64_encode($username),
                    'admin-password' => base64_encode($password),
                ],
            ])));
        }

        return [$username, $password, $isNew];
    }

    /**
     * Resolve the target/source secrets engine consistently across all secrets commands.
     * Returns the SecretsBackend Enum case, or null when flag is missing in non-interactive mode.
     */
    protected function resolveSecretsEngine(
        string $promptLabel = 'Which secrets engine do you want to target?',
        ?SecretsBackend $default = SecretsBackend::OPENBAO,
        ?string $kubectl = null,
        ?string $namespace = null,
    ): ?SecretsBackend {
        $flag = (string) $this->option('engine');

        if ($flag !== '') {
            $backend = SecretsBackend::tryFrom($flag);

            if ($backend !== null) {
                return $backend;
            }

            $allowed = implode(', ', array_map(fn (SecretsBackend $b) => $b->value, SecretsBackend::cases()));
            $this->laraKubeError("Unknown engine \"{$flag}\". Valid values: {$allowed}");

            return null;
        }

        if ($this->cannotPrompt()) {
            return $default;
        }

        $options = array_combine(
            array_map(fn (SecretsBackend $b) => $b->value, SecretsBackend::cases()),
            array_map(fn (SecretsBackend $b) => $b->getLabel(), SecretsBackend::cases()),
        );

        if ($kubectl && $namespace) {
            $detected = [];
            foreach (SecretsBackend::cases() as $backend) {
                $out = trim(Process::run(
                    "{$kubectl} get deployment {$backend->getDeploymentName()} -n {$namespace} --no-headers --ignore-not-found",
                )->output());
                if ($out !== '') {
                    $detected[] = $backend;
                }
            }

            if ($detected !== []) {
                $options = array_combine(
                    array_map(fn ($b) => $b->value, $detected),
                    array_map(fn ($b) => $b->getLabel().' (deployed)', $detected),
                );
            }
        }

        $choice = select(
            label: $promptLabel,
            options: $options,
            default: array_key_first($options) ?: ($default?->value ?? 'openbao'),
        );

        return SecretsBackend::from($choice);
    }
}
