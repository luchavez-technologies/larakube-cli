<?php

namespace App\Traits;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

/**
 * Finding a Supabase instance's Edge Runtime, for the supabase:functions:* commands. Self-hosted Edge
 * Runtime serves each folder on its functions volume at /functions/v1/<name>; `main` is its own router.
 *
 * Needs DeploysClusterTool, InteractsWithClusterContext, LaraKubeOutput and ResolvesToolEnvironment.
 */
trait InteractsWithSupabaseFunctions
{
    protected const string FUNCTIONS_DIR = '/home/deno/functions';

    protected const string FUNCTIONS_CONTAINER = 'supabase-edge-functions';

    /**
     * The instance's Edge Runtime from --domain/--context, or an error message. `enabled` is false when
     * the instance was installed with --no-functions.
     *
     * @return array{context: string, kubectl: string, instance: string, host: ?string, deployment: string, namespace: string, enabled: bool}|string
     */
    protected function resolveFunctionsTarget(): array|string
    {
        $env = $this->resolveToolEnvironment(ClusterTool::SUPABASE);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $instance = $this->resolveInstanceForTool(ClusterTool::SUPABASE, $kubectl, (string) ($this->option('domain') ?: ''));
        if ($instance === false) {
            return 'Supabase has several instances on this cluster. Pass --domain= to pick one.';
        }
        if ($instance === null || $instance === '') {
            return 'Supabase is not installed on this cluster.';
        }

        $names = ToolInstance::forInstance(ClusterTool::SUPABASE, $instance);
        $deployment = $names->name('functions');
        $namespace = $names->namespace();
        $found = Kubectl::forContext($context)->raw(['get', 'deployment', $deployment, '-n', $namespace, '--ignore-not-found', '-o', 'name']);
        $host = $this->getToolInstanceData($kubectl, ClusterTool::SUPABASE, $instance)?->host;

        return [
            'context' => $context,
            'kubectl' => $kubectl,
            'instance' => $instance,
            'host' => $host !== null && $host !== '' ? $host : null,
            'deployment' => $deployment,
            'namespace' => $namespace,
            'enabled' => $found->ok && trim($found->output) !== '',
        ];
    }

    /**
     * The folders on Edge Runtime's functions volume, as name => last-modified Unix time, leaving out its
     * own `main` router. A string is the error when the pod can't be read.
     *
     * @param  array{kubectl: string, deployment: string, namespace: string}  $target
     * @return array<string, ?int>|string
     */
    protected function deployedFunctionFolders(array $target): array|string
    {
        $listing = Process::timeout(60)->run(
            "{$target['kubectl']} exec deploy/{$target['deployment']} -n {$target['namespace']} -c ".self::FUNCTIONS_CONTAINER.' -- '
            .'find '.self::FUNCTIONS_DIR." -mindepth 1 -maxdepth 1 -type d -not -name main -not -name '.*' -printf '%f\\t%T@\\n'",
        );

        if (! $listing->successful()) {
            return 'Could not read the functions volume: '.trim($listing->errorOutput() ?: $listing->output());
        }

        $folders = [];
        foreach (array_filter(explode("\n", trim($listing->output()))) as $line) {
            [$name, $modified] = array_pad(explode("\t", $line, 2), 2, '');
            $folders[$name] = is_numeric($modified) ? (int) $modified : null;
        }

        return $folders;
    }

    /** A function's public URL, or null for a shared folder (`_shared`) or an instance with no host. */
    protected function functionUrl(?string $host, string $name): ?string
    {
        return $host === null || str_starts_with($name, '_') ? null : "https://{$host}/functions/v1/{$name}";
    }
}
