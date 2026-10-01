<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretsBackend;
use App\Enums\SharedClusterService;
use App\Http\Integrations\OpenBao\OpenBaoConnector;
use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

use Saloon\Exceptions\Request\FatalRequestException;

trait InteractsWithSecrets
{
    use PortForwardsToCluster, ReadsClusterSecrets;
    use RequiresFlagsWhenNonInteractive;

    /** Status + body of the last failed secrets API call, for diagnostics. */
    protected ?string $lastSecretsBackendError = null;

    /** Set by a command that is deploying OpenBao right now, before the registry has a row for it. */
    protected ?ToolInstance $secretsNamesOverride = null;

    /** @var array<string, ToolInstance> */
    private array $secretsNamesMemo = [];

    /**
     * The OpenBao instance on this cluster: the one being deployed, else the
     * registered one. Null when none is registered, so a caller can never read
     * or port-forward to a name nothing deploys.
     */
    protected function secretsNames(string $kubectl): ?ToolInstance
    {
        if ($this->secretsNamesOverride !== null) {
            return $this->secretsNamesOverride;
        }

        return $this->secretsNamesMemo[$kubectl] ??= ToolInstance::first($kubectl, ClusterTool::SECRETS);
    }

    /** The in-cluster URL ESO and every generator reach OpenBao on, or null when not installed. */
    protected function openBaoServerUrl(string $kubectl): ?string
    {
        $names = $this->secretsNames($kubectl);

        return $names === null ? null : "http://{$names->deployment()}.{$names->namespace()}.svc.cluster.local:8200";
    }

    /** The dedicated namespace the Secrets Manager lives in. */
    protected function secretsNamespace(): string
    {
        return ClusterTool::SECRETS->namespace();
    }

    /** OpenBao secrets backend Deployment present? */
    protected function isSecretsInstalled(string $kubectl, string $ns): bool
    {
        $names = $this->secretsNames($kubectl);

        return $names !== null && Kubectl::fromPrefix($kubectl)->hasDeployment($ns, $names->deployment());
    }

    /** True when OpenBao's credentials Secret (root token, unseal key) exists. */
    protected function secretsBackendReady(string $kubectl, string $ns): bool
    {
        $names = $this->secretsNames($kubectl);
        if ($names === null) {
            return false;
        }

        $openbao = Process::run("{$kubectl} get secret {$names->secret()} -n {$ns} --no-headers 2>/dev/null")->output();

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
        $kubectl = Kubectl::forContext($context)->prefix();
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
        $names = $this->secretsNames($kubectl);
        if ($names === null) {
            $this->lastSecretsBackendError = 'OpenBao is not registered on this cluster';

            return null;
        }

        $ns = $this->secretsNamespace();
        $service = $names->deployment();
        $port = random_int(30100, 31100);

        $pf = Process::start("{$kubectl} port-forward -n {$ns} svc/{$service} {$port}:8200");

        if (! $this->awaitLocalPort($port, $pf)) {
            $this->lastSecretsBackendError = "port-forward to {$service} never became ready on localhost:{$port}";

            if ($pf->running()) {
                $pf->stop(0, 2);
            }

            return null;
        }

        try {
            $connector = new OpenBaoConnector($port, $token);
            $request = $data !== null
                ? new DynamicRequest($method, $path, $data)
                : new DynamicNoBodyRequest($method, $path);

            $response = $connector->send($request);

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
        } catch (FatalRequestException $e) {
            $this->lastSecretsBackendError = "could not reach {$service} — ".Str::limit($e->getMessage(), 200);

            return null;
        } finally {
            if ($pf->running()) {
                $pf->stop(0, 2);
            }
        }
    }

    /**
     * Read a key from OpenBao's credentials Secret.
     * Returns null when the secret doesn't exist or the key is missing.
     */
    protected function readOpenBaoBootstrapSecret(string $kubectl, string $ns, string $key): ?string
    {
        $names = $this->secretsNames($kubectl);

        return $names === null ? null : $this->readClusterSecretKey($kubectl, $ns, $names->secret(), $key);
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
        $this->withSpin('Checking OpenBao initialization status...', function () use ($kubectl, &$initStatus): void {
            $initStatus = $this->openBaoApi($kubectl, 'GET', '/v1/sys/init');
        });

        if ($initStatus === null) {
            $this->laraKubeError('Could not reach OpenBao. Is its pod running?');

            return null;
        }

        $initialized = $initStatus['initialized'] ?? false;
        $rootToken = null;

        if (! $initialized) {
            $this->withSpin('Initializing OpenBao (1 key share, threshold 1)...', function () use ($kubectl, &$initResult): void {
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

            $this->withSpin('Storing OpenBao bootstrap credentials in cluster...', function () use ($kubectl, $ns, $rootToken, $unsealKey): void {
                $yaml = implode("\n", [
                    'apiVersion: v1',
                    'kind: Secret',
                    'metadata:',
                    "  name: {$this->secretsNames($kubectl)?->secret()}",
                    "  namespace: {$ns}",
                    'type: Opaque',
                    'data:',
                    '  root-token: '.base64_encode($rootToken),
                    '  unseal-key: '.base64_encode($unsealKey),
                ]);

                Kubectl::fromPrefix($kubectl)->apply($yaml);
            });

            $this->withSpin('Unsealing OpenBao...', function () use ($kubectl, $unsealKey): void {
                $this->openBaoApi($kubectl, 'POST', '/v1/sys/unseal', ['key' => $unsealKey]);
            });

            $this->laraKubeInfo('OpenBao initialized and unsealed.');
        } else {
            $rootToken = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');

            if ($rootToken === null) {
                $this->laraKubeError('OpenBao is already initialized but the root token is missing from its credentials Secret.');
                $this->line('  Re-initialize manually or restore that Secret.');

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
        $this->withSpin('Checking OpenBao seal status...', function () use ($kubectl, &$sealStatus): void {
            $sealStatus = $this->openBaoApi($kubectl, 'GET', '/v1/sys/seal-status');
        });

        if (($sealStatus['sealed'] ?? true) !== true) {
            return true;
        }

        $unsealKey = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'unseal-key');
        if ($unsealKey === null) {
            $this->laraKubeError('OpenBao is sealed and the unseal key is missing from its credentials Secret.');

            return false;
        }

        $result = null;
        $this->withSpin('Unsealing OpenBao...', function () use ($kubectl, $unsealKey, &$result): void {
            $result = $this->openBaoApi($kubectl, 'POST', '/v1/sys/unseal', ['key' => $unsealKey]);
        });

        return $result !== null && ($result['sealed'] ?? true) === false;
    }

    /** Seal OpenBao immediately — an incident-response lever, cuts off all secret access until unsealed again. */
    protected function sealOpenBao(string $kubectl, string $ns): bool
    {
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            $this->laraKubeError('OpenBao root token not found in its credentials Secret.');

            return false;
        }

        $result = null;
        $this->withSpin('Sealing OpenBao...', function () use ($kubectl, $token, &$result): void {
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
     * in the credentials Secret, merged in via `kubectl patch --type merge` so
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
            Kubectl::fromPrefix($kubectl)->patchSecret($ns, $this->secretsNames($kubectl)?->secret() ?? '', ['admin-username' => $username, 'admin-password' => $password]);
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
            if ($this->isSecretsInstalled($kubectl, $namespace)) {
                $detected[] = SecretsBackend::OPENBAO;
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
