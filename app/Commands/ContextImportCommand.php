<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class ContextImportCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'context:import {file : Path to the kubeconfig you were given (e.g. lloyd.kubeconfig)}';

    protected $description = 'Import a kubeconfig (from `cluster:grant`) into your ~/.kube/config and switch to it';

    public function handle(): int
    {
        $this->renderHeader();

        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->laraKubeError("Kubeconfig file not found: {$file}");
            $this->line('  <fg=gray>Need one? Ask your cluster admin to run</> <fg=yellow>larakube cluster:grant</> <fg=gray>and send you the file.</>');

            return 1;
        }

        $home = home_path();
        $local = $home.'/.kube/config';

        // Which context are we importing? (Deterministic name → re-import is idempotent.)
        $incoming = trim(Process::run('kubectl config view --kubeconfig='.escapeshellarg($file).' -o jsonpath='.escapeshellarg('{.current-context}'))->output());

        // When run inside a project, align the imported context's NAME to what this
        // project's matching env resolves to — so the teammate gets the same
        // env-first `larakube <cmd> <env>` DX (and it works on managed clusters,
        // where the names would otherwise differ). Works on a throwaway copy, so the
        // teammate's original file and the shared .larakube.json are never touched.
        $source = $file;
        $env = null;
        if (($aligned = $this->alignContextToEnvironment($file, $incoming)) !== null) {
            [$source, $incoming, $env] = $aligned;
        }

        if (file_exists($local)) {
            copy($local, $local.'.bak.'.time());

            // Merge with kubectl's own flatten engine — same-named entries are
            // overwritten (so re-importing the same file is a no-op refresh).
            $merged = Process::run('KUBECONFIG='.escapeshellarg($local).':'.escapeshellarg($source).' kubectl config view --flatten')->output();
            if (trim($merged) === '') {
                $this->laraKubeError('Failed to merge the kubeconfig. Is kubectl installed?');
                $this->cleanupTemp($source, $file);

                return 1;
            }
            file_put_contents($local, $merged);
        } else {
            if (! is_dir(dirname($local))) {
                mkdir(dirname($local), 0700, true);
            }
            copy($source, $local);
        }

        $this->cleanupTemp($source, $file);

        if ($incoming !== '') {
            Process::run('kubectl config use-context '.escapeshellarg($incoming));
            $this->laraKubeInfo("✅ Imported — you're now on context '{$incoming}'.");
        } else {
            $this->laraKubeInfo('✅ Kubeconfig imported.');
        }

        if ($env !== null) {
            $this->line("  <fg=gray>Bound to environment</> <fg=cyan>{$env}</> <fg=gray>— try:</> <fg=yellow>larakube logs {$env}</>  <fg=gray>·</> <fg=yellow>larakube cluster:users {$env}</>");
        } else {
            $this->line('  <fg=gray>Try:</> <fg=yellow>kubectl get pods</>  <fg=gray>· switch later with</> <fg=yellow>larakube context</>');
        }

        return 0;
    }

    /**
     * If we're in a project and the credential's namespace ({app}-{env}) matches one
     * of its environments, rename the imported context to that env's resolved name
     * on a throwaway copy. Returns [sourceFile, contextName, env] or null when there's
     * nothing to align (no project, no match, or already aligned — e.g. a VPS).
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    protected function alignContextToEnvironment(string $file, string $incoming): ?array
    {
        $config = $this->getProjectConfig(getcwd());
        if ($config === null || $incoming === '') {
            return null;
        }

        // The credential's namespace tells us which env it's for.
        $namespace = trim(Process::run('kubectl config view --kubeconfig='.escapeshellarg($file).' --minify -o jsonpath='.escapeshellarg('{.contexts[0].context.namespace}'))->output());
        if ($namespace === '') {
            return null;
        }

        // Match by namespace (honors any namespace override) → that env's own context.
        $env = collect($config->getCloudEnvironments())->first(fn (string $e) => $config->getNamespace($e) === $namespace);
        if ($env === null) {
            return null;
        }

        $target = $this->environmentContextOrCurrent($config, (string) $env);
        if ($target === null || $target === '' || $target === $incoming) {
            return null;   // already aligned (VPS), or no env-specific context
        }

        $tmp = tempnam(sys_get_temp_dir(), 'lk_import_');
        copy($file, $tmp);
        $success = Process::run('kubectl config rename-context '.escapeshellarg($incoming).' '.escapeshellarg($target).' --kubeconfig='.escapeshellarg($tmp))->successful();
        if (! $success) {
            @unlink($tmp);

            return null;
        }

        return [$tmp, $target, (string) $env];
    }

    /** Remove the throwaway copy used for context-renaming (never the original). */
    protected function cleanupTemp(string $source, string $original): void
    {
        if ($source !== $original && is_file($source)) {
            @unlink($source);
        }
    }
}
