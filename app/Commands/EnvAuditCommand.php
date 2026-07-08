<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class EnvAuditCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'env:audit
        {environment? : An environment (in-project) or a literal namespace (standalone) to audit — omit to pick from the project\'s envs}
        {--context= : Standalone: target a kube-context directly (when not in a project)}';

    protected $description = 'List every env var KEY deployed to an environment — names only, never values — the checklist for what to rotate when someone leaves';

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        $arg = (string) ($this->argument('environment') ?? '');

        // Inside a project, drive everything by ENVIRONMENT (same env-first DX as
        // cluster:users) — that also gives us $env, needed to know which secret
        // keys are LaraKube-managed vs. something a human typed into .env.
        if ($config !== null && ($arg === '' || $config->getEnvironment($arg) !== null)) {
            $env = $arg !== '' ? $arg : $this->pickEnvironment($config);
            if ($env === null) {
                $this->laraKubeWarn('This project has no cloud environments yet — add one with `larakube env <name>`.');

                return 0;
            }

            $namespace = $config->getNamespace($env);
            $context = $this->environmentContextOrCurrent($config, $env);
            $known = array_keys($config->getAllSecretEnvironmentVariables($env));

            $this->line('  <fg=gray>Environment:</> <fg=cyan>'.$env.'</>  <fg=gray>·</> <fg=cyan>'.$namespace.'</>  <fg=gray>·</> <fg=cyan>'.($context ?? 'current context').'</>');
            $this->laraKubeNewLine();

            return $this->audit($this->contextKubectl($context), $namespace, $known);
        }

        // Standalone (outside a project, or an explicit literal namespace) — pick
        // a context rather than silently defaulting to whatever kubectl points at.
        // No project config here, so there's no way to tell managed from custom.
        if ($arg === '') {
            $this->laraKubeError('Provide a namespace, or run inside a project to pick an environment.');

            return 1;
        }

        $context = $this->pickContext($this->option('context'));
        if ($context === null) {
            $this->laraKubeError('No kube-contexts found — is kubectl configured?');

            return 1;
        }

        $this->line('  <fg=gray>Context:</> <fg=cyan>'.$context.'</>');
        $this->laraKubeNewLine();

        return $this->audit($this->contextKubectl($context), $arg, null);
    }

    /**
     * Classify one secret key: LaraKube generates + can rotate it in-cluster, it's
     * a human-typed third-party credential ("custom" — the actual rotation
     * checklist), or (standalone run, no project config) we simply can't tell. Pure.
     *
     * @param  array<int, string>|null  $known
     */
    public function classifySource(string $key, ?array $known): string
    {
        if ($known === null) {
            return 'unknown (no project context)';
        }

        return in_array($key, $known, true) ? 'LaraKube-managed' : 'custom';
    }

    /**
     * Print the key names (never values) sitting in laravel-secrets + laravel-config
     * for one namespace. $known is the set of keys LaraKube itself generates and can
     * rotate in-cluster (DB/cache/search passwords, APP_KEY, ...); null means "no
     * project config to compare against" (standalone run).
     *
     * @param  array<int, string>|null  $known
     */
    protected function audit(string $kubectl, string $namespace, ?array $known): int
    {
        $secretKeys = $this->objectDataKeys($kubectl, $namespace, 'secret', 'laravel-secrets');
        $configKeys = $this->objectDataKeys($kubectl, $namespace, 'configmap', 'laravel-config');

        if ($secretKeys === null && $configKeys === null) {
            $this->laraKubeInfo("No 'laravel-secrets' or 'laravel-config' found in '{$namespace}' — nothing deployed yet?");

            return 0;
        }

        if (! empty($secretKeys)) {
            sort($secretKeys);
            $rows = array_map(fn (string $key) => [$key, $this->classifySource($key, $known)], $secretKeys);

            $this->line("  <fg=green>Secrets</> <fg=gray>(laravel-secrets — this is what an 'edit'/'admin' teammate could read)</>");
            table(['Key', 'Source'], $rows);
        } else {
            $this->laraKubeInfo("'laravel-secrets' is empty or missing in '{$namespace}'.");
        }

        if (! empty($configKeys)) {
            sort($configKeys);
            $this->laraKubeNewLine();
            $this->line("  <fg=green>Config</> <fg=gray>(laravel-config — plain values, also visible to 'view' role)</>");
            table(['Key'], array_map(fn (string $key) => [$key], $configKeys));
        }

        $this->laraKubeNewLine();
        $this->line('  <fg=gray>"LaraKube-managed" keys rotate in-cluster; "custom" keys are third-party — rotate them at the provider,</>');
        $this->line('  <fg=gray>then update .env.{environment} and re-run `cloud:deploy` to push the new value live.</>');

        return 0;
    }

    /**
     * Key names (never values) from a Secret or ConfigMap's `.data`. Null when the
     * object doesn't exist; empty array when it exists but is empty.
     *
     * @return array<int, string>|null
     */
    protected function objectDataKeys(string $kubectl, string $namespace, string $kind, string $name): ?array
    {
        $json = Process::run(
            "{$kubectl} get {$kind} ".escapeshellarg($name).' -n '.escapeshellarg($namespace).' -o json',
        )->output();
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['data'])) {
            return null;
        }

        return array_keys((array) $decoded['data']);
    }
}
