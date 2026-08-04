<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsEnvSources;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class DotenvCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput, ReadsEnvSources, ResolvesEnvironmentContext;

    protected $signature = 'dotenv
        {environment? : The environment to compare — omit to pick from the project\'s envs}
        {--reveal : Print plaintext secret values (requires secret-read access on your context)}
        {--strict : Exit 1 on any drift/missing key — for CI/preflight gates. Plex/OpenBao-rotated keys (expected to diverge from the file) never count}
        {--context= : Override the kube-context to compare against}';

    protected $description = 'Compare this project\'s .env.<environment> against the ConfigMap + Secret deployed to the cluster, and surface drift';

    public function handle(): int
    {
        $this->renderHeader();

        // Unlike `dotenv:audit`, this command diffs a *local* .env.<env>, so it
        // only makes sense inside a project — there's no standalone namespace mode.
        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            $this->laraKubeError('Run `dotenv` inside a LaraKube project — it compares this project\'s .env.<environment> against the cluster.');

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

        $namespace = $config->getNamespace($env);
        $context = $this->option('context') ?: $this->environmentContextOrCurrent($config, $env);
        $kubectl = $this->contextKubectl($context);

        $envFile = $config->getPath().($env === 'local' ? '/.env' : '/.env.'.$env);
        if (! is_file($envFile)) {
            $this->laraKubeWarn("No '{$envFile}' on disk — nothing to compare against.");

            return 0;
        }
        $local = $this->parseDotenvVars((string) file_get_contents($envFile));

        // Gate BEFORE touching the Secret: a denied `get secret` returns empty
        // output, indistinguishable from "empty secret" — so we ask the API server
        // whether this context may read secrets and only then fetch them.
        $canReadSecrets = $this->authorizedToReadSecrets($kubectl, $namespace);

        $clusterConfig = $this->readClusterEnvVars('configmap', 'laravel-config', $namespace, false, $kubectl);
        $clusterSecret = $canReadSecrets
            ? $this->readClusterEnvVars('secret', 'laravel-secrets', $namespace, true, $kubectl)
            : [];
        $cluster = array_merge($clusterConfig, $clusterSecret);

        // Register every secret value we now hold (cluster + local) so it can never
        // leak through any laraKube* output, even accidentally.
        $known = array_keys($config->getAllSecretEnvironmentVariables($env));
        foreach ($clusterSecret as $value) {
            $this->registerSecret($value);
        }
        foreach ($local as $key => $value) {
            if ($this->isSecretKey($key, $known)) {
                $this->registerSecret($value);
            }
        }

        $this->line('  <fg=gray>Environment:</> <fg=cyan>'.$env.'</>  <fg=gray>·</> <fg=cyan>'.$namespace.'</>  <fg=gray>·</> <fg=cyan>'.($context ?? 'current context').'</>');
        $this->laraKubeNewLine();

        $reveal = (bool) $this->option('reveal') && $canReadSecrets;
        if ($this->option('reveal') && ! $canReadSecrets) {
            $this->laraKubeWarn("Your context can't read Secrets in '{$namespace}' — showing masked values.");
            $this->laraKubeNewLine();
        }

        $excluded = $config->getPlexManagedKeys($env);

        return $this->compare($local, $cluster, $clusterSecret, $known, $namespace, $canReadSecrets, $reveal, (bool) $this->option('strict'), $excluded);
    }

    /**
     * Ask the API server whether the CALLER's own context may read Secrets in this
     * namespace. Built-in `view` says no (no secrets), `edit`/`admin` say yes — the
     * exact boundary the tool already advertises. `kubectl auth can-i` prints
     * `yes`/`no`.
     */
    protected function authorizedToReadSecrets(string $kubectl, string $namespace): bool
    {
        $out = Process::run(
            $kubectl.' auth can-i get secrets -n '.escapeshellarg($namespace),
        )->output();

        return trim($out) === 'yes';
    }

    /**
     * Render the key-by-key comparison and a drift summary. Secret values are masked
     * unless $reveal; when $canReadSecrets is false, secret keys can't be read from
     * the cluster at all, so they're listed name-only with a "no access" marker.
     *
     * @param  array<string, string>  $local  parsed .env.<env>
     * @param  array<string, string>  $cluster  merged config + secret from the cluster
     * @param  array<string, string>  $clusterSecret  keys that live in laravel-secrets
     * @param  array<int, string>  $known  blueprint-managed secret keys
     * @param  array<int, string>  $excluded  Plex/OpenBao-managed keys — expected to diverge, never fail --strict
     */
    protected function compare(array $local, array $cluster, array $clusterSecret, array $known, string $namespace, bool $canReadSecrets, bool $reveal, bool $strict = false, array $excluded = []): int
    {
        $keys = array_unique(array_merge(array_keys($local), array_keys($cluster)));
        sort($keys);

        if ($keys === []) {
            $this->laraKubeInfo('Nothing to compare — the local file and the cluster are both empty.');

            return 0;
        }

        $rows = [];
        $drift = $onlyLocal = $onlyCluster = $hidden = $maskedVisible = $rotated = 0;

        foreach ($keys as $key) {
            $inLocal = array_key_exists($key, $local);
            $inCluster = array_key_exists($key, $cluster);
            $isSecret = $this->isSecretKey($key, $known) || array_key_exists($key, $clusterSecret);
            $isExcluded = in_array($key, $excluded, true);

            // Secret we're not allowed to read: we can't see the cluster side, so
            // there's no honest comparison — surface the key, mask what we have.
            if ($isSecret && ! $canReadSecrets) {
                $hidden++;
                $rows[] = [$key, 'secret (hidden)', $inLocal ? '••••••' : '—', 'no access'];

                continue;
            }

            $localVal = $inLocal ? $local[$key] : null;
            $clusterVal = $inCluster ? $cluster[$key] : null;

            if ($inLocal && $inCluster) {
                $status = $localVal === $clusterVal ? 'in-sync' : 'drift';
                if ($status === 'drift') {
                    $isExcluded ? $rotated++ : $drift++;
                }
            } elseif ($inLocal) {
                $status = 'only local';
                $isExcluded ? $rotated++ : $onlyLocal++;
            } else {
                $status = 'only cluster';
                $isExcluded ? $rotated++ : $onlyCluster++;
            }

            if ($isExcluded && $status !== 'in-sync') {
                $status = 'rotated (excluded)';
            }

            if ($isSecret && ! $reveal) {
                $maskedVisible++;
            }

            $render = fn (?string $v): string => match (true) {
                $v === null => '—',
                $v === '' => '(empty)',
                $isSecret && ! $reveal => '••••••',
                default => $v,
            };

            $rows[] = [$key, $status, $render($localVal), $render($clusterVal)];
        }

        table(['Key', 'Status', 'Local (.env)', 'Cluster'], $rows);
        $this->laraKubeNewLine();

        if ($drift === 0 && $onlyLocal === 0 && $onlyCluster === 0 && $hidden === 0) {
            $this->line('  <fg=green>✔ Local .env and cluster are in sync.</>');
        } else {
            $summary = [];
            $drift and $summary[] = "<fg=yellow>{$drift} drifted</>";
            $onlyLocal and $summary[] = "<fg=cyan>{$onlyLocal} only in .env</>";
            $onlyCluster and $summary[] = "<fg=cyan>{$onlyCluster} only in cluster</>";
            $hidden and $summary[] = "<fg=gray>{$hidden} secret".($hidden === 1 ? '' : 's').' hidden</>';
            $this->line('  '.implode('  <fg=gray>·</>  ', $summary));
        }

        if ($rotated > 0) {
            $this->line("  <fg=gray>{$rotated} Plex/OpenBao-rotated key".($rotated === 1 ? '' : 's')." excluded from drift — the local file isn't the source of truth for ".($rotated === 1 ? 'it' : 'them').'.</>');
        }

        if ($hidden > 0) {
            $this->laraKubeNewLine();
            $this->line("  <fg=yellow>Secret values are hidden — your context can't read Secrets in '{$namespace}'.</>");
            $this->line('  <fg=gray>Ask an admin for edit/admin access (`larakube cluster:grant --edit`) to compare them.</>');
        } elseif ($maskedVisible > 0 && ! $reveal) {
            $this->line('  <fg=gray>Secret values masked — re-run with --reveal to print them.</>');
        }

        if ($strict && ($drift > 0 || $onlyLocal > 0 || $onlyCluster > 0 || $hidden > 0)) {
            return 1;
        }

        return 0;
    }
}
