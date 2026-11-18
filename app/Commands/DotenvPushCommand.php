<?php

namespace App\Commands;

use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\PushesBuildEnvToCi;
use App\Traits\ReadsEnvSources;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsClusterSecrets;
use LaravelZero\Framework\Commands\Command;

class DotenvPushCommand extends Command
{
    use EmitsJsonOutput, InteractsWithProjectConfig, InteractsWithRemoteDeploy, InteractsWithSecrets, LaraKubeOutput, PushesBuildEnvToCi, ReadsEnvSources, ResolvesEnvironmentContext, SyncsClusterSecrets;

    protected $signature = 'dotenv:push
        {environment? : The environment to push — omit to pick from the project\'s envs}
        {--app= : App name OpenBao secrets are scoped under (defaults to this project\'s name)}
        {--context= : Override the kube-context to push to}
        {--json : Emit machine-readable JSON output}';

    protected $description = "Write .env.<environment>'s secret keys into the cluster (laravel-secrets) directly from this machine — never through CI. Uses OpenBao when present, a direct kubectl apply otherwise.";

    public function handle(): int
    {
        if ($this->option('json')) {
            $this->enableJsonMode();
        } else {
            $this->renderHeader();
        }

        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => 'Run `dotenv:push` inside a LaraKube project.']);
            } else {
                $this->laraKubeError('Run `dotenv:push` inside a LaraKube project.');
            }

            return 1;
        }

        $arg = (string) ($this->argument('environment') ?? '');
        $env = $arg !== '' ? $arg : ($this->option('json') || ! $this->input->isInteractive() ? ($config->getEnvironments()[0] ?? null) : $this->pickEnvironment($config));
        if ($env === null) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => 'This project has no cloud environments yet.']);
            } else {
                $this->laraKubeWarn('This project has no cloud environments yet — add one with `larakube env <name>`.');
            }

            return 0;
        }
        if ($config->getEnvironment($env) === null) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => "No '{$env}' environment in this project."]);
            } else {
                $this->laraKubeError("No '{$env}' environment in this project — run `larakube env {$env}` first.");
            }

            return 1;
        }

        $envFile = $config->getPath().($env === 'local' ? '/.env' : '/.env.'.$env);
        if (! is_file($envFile)) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => "No '{$envFile}' on disk — nothing to push."]);
            } else {
                $this->laraKubeError("No '{$envFile}' on disk — nothing to push.");
            }

            return 1;
        }

        $namespace = $config->getNamespace($env);

        // Static SPAs (Docusaurus, Vite, Astro) run Caddy with zero runtime
        // Secret/ConfigMap — push all .env.<env> variables to CI ({ENV}_BUILD_ENV).
        if ($config->framework?->isStaticSpa() && $env !== 'local') {
            $local = $this->parseDotenvVars((string) file_get_contents($envFile));
            if ($local === []) {
                if ($this->option('json')) {
                    $this->jsonOutput([
                        'success' => true,
                        'environment' => $env,
                        'namespace' => $namespace,
                        'backend' => $this->detectCiPlatform($config, $env),
                        'pushed' => 0,
                        'keys' => [],
                        'message' => "No variables found in '{$envFile}' — nothing to push.",
                    ]);
                } else {
                    $this->laraKubeWarn("No variables found in '{$envFile}' — nothing to push.");
                }

                return 0;
            }

            $ciResult = $this->pushBuildEnvToCi($config, $env, $config->getPath());
            if (! $ciResult['pushed']) {
                $error = $ciResult['error'] ?? 'Failed to upload CI build environment.';
                if ($this->option('json')) {
                    $this->jsonOutput([
                        'success' => false,
                        'environment' => $env,
                        'backend' => $ciResult['platform'],
                        'secretName' => $ciResult['secretName'],
                        'error' => $error,
                    ]);
                } else {
                    $this->laraKubeError($error);
                }

                return 1;
            }

            if ($this->option('json')) {
                $this->jsonOutput([
                    'success' => true,
                    'environment' => $env,
                    'namespace' => $namespace,
                    'backend' => $ciResult['platform'],
                    'secretName' => $ciResult['secretName'],
                    'pushed' => $ciResult['count'],
                    'keys' => $ciResult['keys'],
                ]);
            } else {
                $this->laraKubeInfo("Pushed {$ciResult['count']} build-time variable(s) from .env.{$env} to {$ciResult['platform']} secret '{$ciResult['secretName']}'.");
            }

            return 0;
        }

        $context = $this->option('context') ?: $this->environmentContextOrCurrent($config, $env);
        $kubectl = Kubectl::forContext($context)->prefix();
        $app = (string) ($this->option('app') ?: $config->getName());

        if (! $this->option('json')) {
            $this->line('  <fg=gray>Environment:</> <fg=cyan>'.$env.'</>  <fg=gray>·</> <fg=cyan>'.$namespace.'</>  <fg=gray>·</> <fg=cyan>app='.$app.'</>');
            $this->laraKubeNewLine();
        }

        $local = $this->parseDotenvVars((string) file_get_contents($envFile));
        $knownSecrets = array_keys($config->getAllSecretEnvironmentVariables($env));
        $toPush = array_filter(
            $local,
            fn (string $value, string $key) => $this->isSecretKey($key, $knownSecrets) || $this->hasEmbeddedCredentials($value),
            ARRAY_FILTER_USE_BOTH,
        );

        // Plex/OpenBao owns these regardless of what the local file happens to
        // hold — a manually-added or stale DB_PASSWORD must never overwrite a
        // rotated credential. Excluded from $toPush, not just masked, per the
        // plan's precedence rule (secrets:push skips overwriting DB_PASSWORD).
        $plexManaged = array_intersect($config->getPlexManagedKeys($env), array_keys($toPush));
        if ($plexManaged !== []) {
            if (! $this->option('json')) {
                $this->laraKubeWarn(implode(', ', $plexManaged).' — managed by Plex/OpenBao, excluded from push (the local value is never authoritative for these).');
            }
            $toPush = array_diff_key($toPush, array_flip($plexManaged));
        }

        if ($toPush === []) {
            $ciSync = $this->syncHybridBuildEnvIfConfigured($config, $env);
            if ($ciSync !== null && $ciSync['pushed']) {
                if ($this->option('json')) {
                    $this->jsonOutput([
                        'success' => true,
                        'environment' => $env,
                        'namespace' => $namespace,
                        'backend' => $ciSync['platform'],
                        'secretName' => $ciSync['secretName'],
                        'pushed' => $ciSync['count'],
                        'keys' => $ciSync['keys'],
                        'ci' => $ciSync,
                    ]);
                } else {
                    $this->laraKubeInfo("Pushed {$ciSync['count']} build-time variable(s) from .env.{$env} to {$ciSync['platform']} secret '{$ciSync['secretName']}'.");
                }

                return 0;
            }

            if ($this->option('json')) {
                $this->jsonOutput(['success' => true, 'pushed' => 0, 'keys' => [], 'message' => "No known secret keys found in '{$envFile}' — nothing to push."]);
            } else {
                $this->laraKubeWarn("No known secret keys found in '{$envFile}' — nothing to push.");
            }

            return 0;
        }

        if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            return $this->pushToOpenBao($kubectl, $namespace, $env, $app, $toPush, $config);
        }

        if (! $this->option('json')) {
            $this->laraKubeInfo('OpenBao not detected on this cluster — writing directly to the cluster Secret.');
        }
        $this->syncRemoteEnv($config, $env, $context, $namespace);
        $ciSync = $this->syncHybridBuildEnvIfConfigured($config, $env);

        if ($this->option('json')) {
            $payload = [
                'success' => true,
                'environment' => $env,
                'namespace' => $namespace,
                'backend' => 'secret',
                'pushed' => count($toPush),
                'keys' => array_keys($toPush),
            ];
            if ($ciSync !== null) {
                $payload['ci'] = $ciSync;
            }
            $this->jsonOutput($payload);
        } else {
            $targetSecret = $this->resolveSecretName($namespace, $kubectl, $config);
            $targetConfig = $this->resolveConfigMapName($namespace, $kubectl, $config);
            $this->laraKubeInfo("Pushed .env.{$env} to '{$targetSecret}'/'{$targetConfig}' in '{$namespace}'.");
            $this->reportHybridCiSync($ciSync);
        }

        return 0;
    }

    /**
     * @param  array<string, string>  $toPush
     */
    protected function pushToOpenBao(string $kubectl, string $namespace, string $env, string $app, array $toPush, ?\App\Data\ConfigData $config = null): int
    {
        $targetSecret = $this->resolveSecretName($namespace, $kubectl, $config);
        $ok = true;
        $pushAction = function () use (&$ok, $kubectl, $env, $app, $toPush): void {
            foreach ($toPush as $key => $value) {
                if (! $this->pushClusterSecret($kubectl, "{$app}/{$key}", $value, $env)) {
                    $ok = false;
                }
            }
        };

        if ($this->option('json')) {
            $pushAction();
        } else {
            $this->withSpin('Pushing '.count($toPush)." secret key(s) to OpenBao ({$app}/{$env})...", $pushAction);
        }

        if (! $ok) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => 'One or more keys failed to write to OpenBao.']);
            } else {
                $this->laraKubeError('One or more keys failed to write to OpenBao — see above.');
            }

            return 1;
        }

        $syncAction = function () use (&$ok, $kubectl, $namespace, $env, $app, $targetSecret): void {
            $ok = $this->syncClusterSecretToNamespace($kubectl, $namespace, $targetSecret, $env, prefix: $app);
        };

        if ($this->option('json')) {
            $syncAction();
        } else {
            $this->withSpin("Syncing '{$targetSecret}' in '{$namespace}' from OpenBao...", $syncAction);
        }

        if (! $ok) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => "Could not sync '{$targetSecret}' into '{$namespace}' from OpenBao."]);
            } else {
                $this->laraKubeError("Could not sync '{$targetSecret}' into '{$namespace}' from OpenBao.");
            }

            return 1;
        }

        $ciSync = $config !== null ? $this->syncHybridBuildEnvIfConfigured($config, $env) : null;

        if ($this->option('json')) {
            $payload = [
                'success' => true,
                'environment' => $env,
                'namespace' => $namespace,
                'backend' => 'openbao',
                'pushed' => count($toPush),
                'keys' => array_keys($toPush),
            ];
            if ($ciSync !== null) {
                $payload['ci'] = $ciSync;
            }
            $this->jsonOutput($payload);
        } else {
            $this->laraKubeInfo('Pushed '.count($toPush)." key(s) to OpenBao and synced '{$targetSecret}' in '{$namespace}'.");
            $this->reportHybridCiSync($ciSync);
        }

        return 0;
    }

    /**
     * Best-effort sync of build-time prefix vars (VITE_*, NEXT_PUBLIC_*, PUBLIC_*)
     * to CI ({ENV}_BUILD_ENV) when a workflow exists for this environment.
     *
     * @return array{pushed: bool, platform: string, secretName: string, count: int, keys: list<string>, error: ?string}|null
     */
    protected function syncHybridBuildEnvIfConfigured(\App\Data\ConfigData $config, string $env): ?array
    {
        $path = $config->getPath();
        if ($env === 'local' || ! $path || ! $this->hasConfiguredCiWorkflow($path, $env)) {
            return null;
        }

        if ($this->buildEnvPayload($config, $env, $path) === '') {
            return null;
        }

        return $this->pushBuildEnvToCi($config, $env, $path);
    }

    /**
     * @param  array{pushed: bool, platform: string, secretName: string, count: int, keys: list<string>, error: ?string}|null  $ciSync
     */
    protected function reportHybridCiSync(?array $ciSync): void
    {
        if ($ciSync === null) {
            return;
        }

        if ($ciSync['pushed']) {
            $this->laraKubeInfo("Synced {$ciSync['count']} build-time variable(s) to {$ciSync['platform']} secret '{$ciSync['secretName']}'.");
        } elseif ($ciSync['error']) {
            $this->laraKubeWarn("Cluster push succeeded, but CI build env sync skipped: {$ciSync['error']}");
        }
    }
}
