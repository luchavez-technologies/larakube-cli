<?php

namespace App\Commands\Supabase;

use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSupabaseFunctions;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * The Edge Functions deployed to a self-hosted Supabase: every folder on Edge Runtime's functions volume
 * except its own `main` router, read from inside the pod.
 */
class SupabaseFunctionsListCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithClusterContext, InteractsWithSupabaseFunctions, LaraKubeOutput, ResolvesToolEnvironment;

    protected $signature = 'supabase:functions:list
        {environment=local : Environment Supabase is installed in}
        {--domain= : The Supabase instance (its host); omit when there is only one}
        {--context= : Target a specific kube-context}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the Edge Functions deployed to a self-hosted Supabase';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        }

        $target = $this->resolveFunctionsTarget();
        if (is_string($target)) {
            return $this->failWith($json, $target);
        }

        $result = ['success' => true, 'instance' => $target['instance'], 'host' => $target['host'], 'enabled' => $target['enabled'], 'functions' => []];

        if (! $target['enabled']) {
            return $this->finish($json, $result, 'Edge Functions are turned off for this Supabase.');
        }

        $folders = $this->deployedFunctionFolders($target);
        if (is_string($folders)) {
            return $this->failWith($json, $folders);
        }

        $functions = [];
        foreach ($folders as $name => $modified) {
            $name = (string) $name;
            $functions[] = [
                'name' => $name,
                'shared' => str_starts_with($name, '_'),
                'url' => $this->functionUrl($target['host'], $name),
                'updatedAt' => $modified !== null ? date(DATE_ATOM, $modified) : null,
            ];
        }
        usort($functions, fn (array $a, array $b): int => [$a['shared'], $a['name']] <=> [$b['shared'], $b['name']]);
        $result['functions'] = $functions;

        return $this->finish($json, $result, $functions === [] ? 'No Edge Functions deployed yet. Deploy some with supabase:functions:deploy.' : null);
    }

    /** @param  array<string, mixed>  $result */
    private function finish(bool $json, array $result, ?string $note): int
    {
        if ($json) {
            $this->jsonOutput($result);

            return 0;
        }

        if ($result['functions'] !== []) {
            table(
                headers: ['Function', 'URL', 'Updated'],
                rows: array_map(fn (array $f): array => [$f['name'], $f['url'] ?? ($f['shared'] ? 'shared code' : '—'), $f['updatedAt'] ?? '—'], $result['functions']),
            );
        }

        if ($note !== null) {
            $this->laraKubeInfo($note);
        }

        return 0;
    }

    private function failWith(bool $json, string $message): int
    {
        if ($json) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        } else {
            $this->laraKubeError($message);
        }

        return 1;
    }
}
