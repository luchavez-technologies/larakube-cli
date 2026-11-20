<?php

namespace App\Commands\Supabase;

use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSupabaseFunctions;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\table;

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

        $listing = Process::timeout(60)->run(
            "{$target['kubectl']} exec deploy/{$target['deployment']} -n {$target['namespace']} -c ".self::FUNCTIONS_CONTAINER.' -- '
            .'find '.self::FUNCTIONS_DIR." -mindepth 1 -maxdepth 1 -type d -not -name main -not -name '.*' -printf '%f\\t%T@\\n'",
        );

        if (! $listing->successful()) {
            return $this->failWith($json, 'Could not read the functions volume: '.trim($listing->errorOutput() ?: $listing->output()));
        }

        $functions = [];
        foreach (array_filter(explode("\n", trim($listing->output()))) as $line) {
            [$name, $modified] = array_pad(explode("\t", $line, 2), 2, '');
            $functions[] = [
                'name' => $name,
                'shared' => str_starts_with($name, '_'),
                'url' => $this->functionUrl($target['host'], $name),
                'updatedAt' => is_numeric($modified) ? date(DATE_ATOM, (int) $modified) : null,
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
