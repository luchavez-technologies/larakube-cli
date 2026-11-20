<?php

namespace App\Commands\Supabase;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Open Supabase's MCP server on this machine, for Claude Code and other AI clients. Self-hosted
 * Supabase serves MCP from Studio with no login of its own and full access to the database, so its
 * gateway blocks /mcp for everyone. This reaches Studio through a kubectl port-forward bound to
 * 127.0.0.1 instead: only this machine can use it, and nothing on the server changes. It runs until
 * stopped.
 */
class SupabaseMcpCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, LaraKubeOutput, ResolvesToolEnvironment;

    /** Studio's port inside the cluster, and where it serves MCP with the tools the client may use. */
    private const int STUDIO_PORT = 3000;

    public const string MCP_PATH = '/api/mcp?features=docs%2Cdatabase%2Cdebugging%2Cdevelopment';

    protected $signature = 'supabase:mcp
        {environment=local : Environment Supabase is installed in}
        {--domain= : The Supabase instance (its host); omit when there is only one}
        {--port=54321 : Local port to serve MCP on}
        {--context= : Target a specific kube-context}';

    protected $description = "Open a self-hosted Supabase's MCP server on this machine for AI clients";

    public function handle(): int
    {
        $this->renderHeader();

        $port = (int) $this->option('port');
        if ($port < 1024 || $port > 65535) {
            $this->laraKubeError('--port must be between 1024 and 65535.');

            return 1;
        }

        $env = $this->resolveToolEnvironment(ClusterTool::SUPABASE);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $instance = $this->resolveInstanceForTool(ClusterTool::SUPABASE, $kubectl, (string) ($this->option('domain') ?: ''));
        if ($instance === false) {
            $this->laraKubeError('Supabase has several instances on this cluster. Pass --domain= to pick one.');

            return 1;
        }
        if ($instance === null || $instance === '') {
            $this->laraKubeError('Supabase is not installed on this cluster.');

            return 1;
        }

        $names = ToolInstance::forInstance(ClusterTool::SUPABASE, $instance);
        $service = $names->name('studio');
        $url = "http://127.0.0.1:{$port}".self::MCP_PATH;

        $this->laraKubeInfo("Supabase MCP is open at {$url}");
        $this->line('  Add it to Claude Code with:');
        $this->line("  <fg=blue>claude mcp add --transport http supabase \"{$url}\"</>");
        $this->line('  <fg=gray>It can read and change your whole database. Only this machine can reach it. Press Ctrl+C to close it.</>');
        $this->newLine();

        $tunnel = Process::forever()->run(
            "{$kubectl} port-forward --address 127.0.0.1 -n {$names->namespace()} svc/{$service} {$port}:".self::STUDIO_PORT,
        );

        if (! $tunnel->successful()) {
            $this->laraKubeError('The tunnel closed: '.trim($tunnel->errorOutput() ?: $tunnel->output()));

            return 1;
        }

        return 0;
    }
}
