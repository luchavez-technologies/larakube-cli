<?php

namespace App\Commands;

use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsEnvSources;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsClusterSecrets;
use LaravelZero\Framework\Commands\Command;

class DotenvPullCommand extends Command
{
    use EmitsJsonOutput, InteractsWithProjectConfig, InteractsWithSecrets, LaraKubeOutput, ReadsEnvSources, ResolvesEnvironmentContext, SyncsClusterSecrets;

    protected $signature = 'dotenv:pull
        {environment? : The environment to pull — omit to pick from the project\'s envs}
        {--app= : App name OpenBao secrets are scoped under (defaults to this project\'s name)}
        {--context= : Override the kube-context to pull from}
        {--json : Emit machine-readable JSON output}';

    protected $description = "Seed .env.<environment>'s secret keys from the cluster — for onboarding a new machine or recovering after a rotation. The read counterpart to dotenv:push.";

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
                $this->jsonOutput(['success' => false, 'error' => 'Run `dotenv:pull` inside a LaraKube project.']);
            } else {
                $this->laraKubeError('Run `dotenv:pull` inside a LaraKube project.');
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
        if ($config->isLocked($envFile)) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => false, 'error' => "'{$envFile}' is locked — skipping (remove the lock in .larakube.json to allow pulls)."]);
            } else {
                $this->laraKubeWarn("'{$envFile}' is locked — skipping (remove the lock in .larakube.json to allow pulls).");
            }

            return 0;
        }

        $namespace = $config->getNamespace($env);
        $context = $this->option('context') ?: $this->environmentContextOrCurrent($config, $env);
        $kubectl = Kubectl::forContext($context)->prefix();
        $app = (string) ($this->option('app') ?: $config->getName());

        if (! $this->option('json')) {
            $this->line('  <fg=gray>Environment:</> <fg=cyan>'.$env.'</>  <fg=gray>·</> <fg=cyan>'.$namespace.'</>  <fg=gray>·</> <fg=cyan>app='.$app.'</>');
            $this->laraKubeNewLine();
        }

        if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            $pulled = $this->readOpenBaoKeys($kubectl, $env, $app);
            if ($pulled === null) {
                if ($this->option('json')) {
                    $this->jsonOutput(['success' => false, 'error' => 'Could not reach OpenBao to pull secrets.']);
                } else {
                    $this->laraKubeError('Could not reach OpenBao to pull secrets.');
                }

                return 1;
            }
        } else {
            if (! $this->option('json')) {
                $this->laraKubeInfo('OpenBao not detected on this cluster — reading directly from the cluster Secret.');
            }
            $pulled = $this->readClusterEnvVars('secret', 'laravel-secrets', $namespace, true, $kubectl);
        }

        if ($pulled === []) {
            if ($this->option('json')) {
                $this->jsonOutput(['success' => true, 'pulled' => 0, 'keys' => [], 'message' => "No secret keys found for '{$app}' in '{$env}' — nothing to pull."]);
            } else {
                $this->laraKubeWarn("No secret keys found for '{$app}' in '{$env}' — nothing to pull.");
            }

            return 0;
        }

        $this->writePulledEnvFile($envFile, $pulled);

        if ($this->option('json')) {
            $this->jsonOutput([
                'success' => true,
                'environment' => $env,
                'namespace' => $namespace,
                'pulled' => count($pulled),
                'keys' => array_keys($pulled),
            ]);
        } else {
            $this->laraKubeInfo('Pulled '.count($pulled)." key(s) into .env.{$env}.");
        }

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
