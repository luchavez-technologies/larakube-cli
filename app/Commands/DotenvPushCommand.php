<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsEnvSources;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsClusterSecrets;
use LaravelZero\Framework\Commands\Command;

class DotenvPushCommand extends Command
{
    use InteractsWithProjectConfig, InteractsWithRemoteDeploy, InteractsWithSecrets, LaraKubeOutput, ReadsEnvSources, ResolvesEnvironmentContext, SyncsClusterSecrets;

    protected $signature = 'dotenv:push
        {environment? : The environment to push — omit to pick from the project\'s envs}
        {--app= : App name OpenBao secrets are scoped under (defaults to this project\'s name)}
        {--context= : Override the kube-context to push to}';

    protected $description = "Write .env.<environment>'s secret keys into the cluster (laravel-secrets) directly from this machine — never through CI. Uses OpenBao when present, a direct kubectl apply otherwise.";

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            $this->laraKubeError('Run `dotenv:push` inside a LaraKube project.');

            return 1;
        }

        $arg = (string) ($this->argument('environment') ?? '');
        $env = $arg !== '' ? $arg : $this->pickEnvironment($config);
        if ($env === null) {
            $this->laraKubeWarn('This project has no cloud environments yet — add one with `larakube env <name>`.');

            return 0;
        }
        if ($config->getEnvironment($env) === null) {
            $this->laraKubeError("No '{$env}' environment in this project — run `larakube env {$env}` first.");

            return 1;
        }

        $envFile = $config->getPath().($env === 'local' ? '/.env' : '/.env.'.$env);
        if (! is_file($envFile)) {
            $this->laraKubeError("No '{$envFile}' on disk — nothing to push.");

            return 1;
        }

        $namespace = $config->getNamespace($env);
        $context = $this->option('context') ?: $this->environmentContextOrCurrent($config, $env);
        $kubectl = $this->contextKubectl($context);
        $app = (string) ($this->option('app') ?: $config->getName());

        $this->line('  <fg=gray>Environment:</> <fg=cyan>'.$env.'</>  <fg=gray>·</> <fg=cyan>'.$namespace.'</>  <fg=gray>·</> <fg=cyan>app='.$app.'</>');
        $this->laraKubeNewLine();

        $local = $this->parseDotenvVars((string) file_get_contents($envFile));
        $knownSecrets = array_keys($config->getAllSecretEnvironmentVariables($env));
        $toPush = array_filter($local, fn (string $key) => $this->isSecretKey($key, $knownSecrets), ARRAY_FILTER_USE_KEY);

        // Plex/OpenBao owns these regardless of what the local file happens to
        // hold — a manually-added or stale DB_PASSWORD must never overwrite a
        // rotated credential. Excluded from $toPush, not just masked, per the
        // plan's precedence rule (secrets:push skips overwriting DB_PASSWORD).
        $plexManaged = array_intersect($config->getPlexManagedKeys($env), array_keys($toPush));
        if ($plexManaged !== []) {
            $this->laraKubeWarn(implode(', ', $plexManaged).' — managed by Plex/OpenBao, excluded from push (the local value is never authoritative for these).');
            $toPush = array_diff_key($toPush, array_flip($plexManaged));
        }

        if ($toPush === []) {
            $this->laraKubeWarn("No known secret keys found in '{$envFile}' — nothing to push.");

            return 0;
        }

        if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            return $this->pushToOpenBao($kubectl, $namespace, $env, $app, $toPush);
        }

        $this->laraKubeInfo('OpenBao not detected on this cluster — writing directly to the cluster Secret.');
        $this->syncRemoteEnv($config, $env, $context, $namespace);
        $this->laraKubeInfo("Pushed .env.{$env} to 'laravel-secrets'/'laravel-config' in '{$namespace}'.");

        return 0;
    }

    /**
     * @param  array<string, string>  $toPush
     */
    protected function pushToOpenBao(string $kubectl, string $namespace, string $env, string $app, array $toPush): int
    {
        $ok = true;
        $this->withSpin('Pushing '.count($toPush)." secret key(s) to OpenBao ({$app}/{$env})...", function () use (&$ok, $kubectl, $env, $app, $toPush) {
            foreach ($toPush as $key => $value) {
                if (! $this->pushClusterSecret($kubectl, "{$app}/{$key}", $value, $env)) {
                    $ok = false;
                }
            }
        });

        if (! $ok) {
            $this->laraKubeError('One or more keys failed to write to OpenBao — see above.');

            return 1;
        }

        $this->withSpin("Syncing 'laravel-secrets' in '{$namespace}' from OpenBao...", function () use (&$ok, $kubectl, $namespace, $env, $app) {
            $ok = $this->syncClusterSecretToNamespace($kubectl, $namespace, 'laravel-secrets', $env, prefix: $app);
        });

        if (! $ok) {
            $this->laraKubeError("Could not sync 'laravel-secrets' into '{$namespace}' from OpenBao.");

            return 1;
        }

        $this->laraKubeInfo('Pushed '.count($toPush)." key(s) to OpenBao and synced 'laravel-secrets' in '{$namespace}'.");

        return 0;
    }
}
