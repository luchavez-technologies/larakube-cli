<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsEnvSources;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsClusterSecrets;
use LaravelZero\Framework\Commands\Command;

class DotenvPullCommand extends Command
{
    use InteractsWithProjectConfig, InteractsWithSecrets, LaraKubeOutput, ReadsEnvSources, ResolvesEnvironmentContext, SyncsClusterSecrets;

    protected $signature = 'dotenv:pull
        {environment? : The environment to pull — omit to pick from the project\'s envs}
        {--app= : App name OpenBao secrets are scoped under (defaults to this project\'s name)}
        {--context= : Override the kube-context to pull from}';

    protected $description = "Seed .env.<environment>'s secret keys from the cluster — for onboarding a new machine or recovering after a rotation. The read counterpart to dotenv:push.";

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            $this->laraKubeError('Run `dotenv:pull` inside a LaraKube project.');

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
        if ($config->isLocked($envFile)) {
            $this->laraKubeWarn("'{$envFile}' is locked — skipping (remove the lock in .larakube.json to allow pulls).");

            return 0;
        }

        $namespace = $config->getNamespace($env);
        $context = $this->option('context') ?: $this->environmentContextOrCurrent($config, $env);
        $kubectl = $this->contextKubectl($context);
        $app = (string) ($this->option('app') ?: $config->getName());

        $this->line('  <fg=gray>Environment:</> <fg=cyan>'.$env.'</>  <fg=gray>·</> <fg=cyan>'.$namespace.'</>  <fg=gray>·</> <fg=cyan>app='.$app.'</>');
        $this->laraKubeNewLine();

        if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            $pulled = $this->readOpenBaoKeys($kubectl, $env, $app);
            if ($pulled === null) {
                $this->laraKubeError('Could not reach OpenBao to pull secrets.');

                return 1;
            }
        } else {
            $this->laraKubeInfo('OpenBao not detected on this cluster — reading directly from the cluster Secret.');
            $pulled = $this->readClusterEnvVars('secret', 'laravel-secrets', $namespace, true, $kubectl);
        }

        if ($pulled === []) {
            $this->laraKubeWarn("No secret keys found for '{$app}' in '{$env}' — nothing to pull.");

            return 0;
        }

        $this->writePulledEnvFile($envFile, $pulled);

        $this->laraKubeInfo('Pulled '.count($pulled)." key(s) into .env.{$env}.");

        return 0;
    }

    /**
     * Merge pulled key=value pairs into $envFile, creating it if it doesn't
     * exist yet — the common case for onboarding a fresh clone, where
     * neither `.env` nor `.env.{environment}` exists. Existing lines for a
     * pulled key are replaced in place; everything else on disk survives
     * untouched.
     *
     * @param  array<string, string>  $pulled
     */
    protected function writePulledEnvFile(string $envFile, array $pulled): void
    {
        $lines = is_file($envFile) ? explode("\n", (string) file_get_contents($envFile)) : [];
        $newLines = [];
        $seen = [];

        foreach ($lines as $line) {
            $matched = false;
            foreach ($pulled as $key => $value) {
                if (preg_match('/^#?\s*'.preg_quote($key, '/').'=.*/', $line)) {
                    $newLines[] = "{$key}={$value}";
                    $seen[$key] = true;
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $newLines[] = $line;
            }
        }

        foreach ($pulled as $key => $value) {
            if (! isset($seen[$key])) {
                $newLines[] = "{$key}={$value}";
            }
        }

        file_put_contents($envFile, implode("\n", $newLines));
    }
}
