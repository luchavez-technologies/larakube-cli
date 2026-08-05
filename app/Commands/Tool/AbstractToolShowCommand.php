<?php

namespace App\Commands\Tool;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Base for every `{tool}:show {environment}` command.
 *
 * Before this, only 7 of 24 tools had a `:show`, so "where does this thing
 * live?" had a different answer per tool — check the docs, re-read the init
 * output, or `kubectl get ingress` by hand. The host is already derivable from
 * ClusterTool::service() (a SharedClusterService knows its own hostFor()), so
 * the remaining 17 needed no new per-tool knowledge, just this base.
 *
 * Resolution order for the URL, most to least authoritative:
 *   1. the host recorded in the cluster tool registry by the last deploy
 *   2. the host pinned in .larakube.json for this environment
 *   3. the conventional host derived from the environment's web host
 *   4. nothing → tell the operator to run `{tool}:init`, exit 1
 */
abstract class AbstractToolShowCommand extends Command
{
    use DeploysClusterTool, LaraKubeOutput;

    public function __construct()
    {
        $tool = $this->tool();

        $this->signature = "{$tool->value}:show
        {environment=local : Environment to show ".$tool->getLabel()." access for}
        {--context= : Target a specific kube-context (defaults to the environment's saved cloud target)}
        {--json     : Emit one machine-readable JSON object on stdout instead of a table}";

        $this->description = "Show how to reach {$tool->getLabel()}";

        parent::__construct();
    }

    public function handle(): int
    {
        $tool = $this->tool();
        $env = (string) $this->argument('environment');

        if (! $this->option('json')) {
            $this->renderHeader();
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        // Pins KUBECONFIG to ~/.kube/config, same as every tool's own helper.
        $kubectl = $this->contextKubectl($context);

        // The registry is a convenience index, NOT the source of truth: only a
        // handful of {tool}:init commands ever call registerDeployedTool(), so
        // trusting it alone reported long-running installs as "not installed"
        // (sso:show said Zitadel was missing while sso-zitadel had been up for
        // days). Fall back to asking the cluster itself.
        $installed = $this->isToolRegistered($kubectl, $tool)
            || $this->isToolPresentOnCluster($kubectl, $tool);
        $host = $this->resolveHost($tool, $env, $kubectl);
        $rows = $this->rows($host, $env, $kubectl);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'tool' => $tool->value,
                'environment' => $env,
                'installed' => $installed,
                'namespace' => $tool->namespace(),
                'host' => $host,
                'url' => $host !== null ? "https://{$host}" : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $installed ? 0 : 1;
        }

        if (! $installed) {
            $this->warn("  {$tool->getLabel()} is not installed in {$tool->namespace()} ('{$env}').");
            $this->line("  Run <fg=yellow>larakube {$tool->initCommand()} {$env}</> to deploy it.");

            return 1;
        }

        table(['Component', 'Access'], $rows);

        $this->afterTable($host, $env);

        return 0;
    }

    abstract protected function tool(): ClusterTool;

    /**
     * The table body. Default is a single URL row; tools with extra endpoints
     * (an admin console, an SSH port, a metrics path) override this.
     *
     * @return list<list<string>>
     */
    protected function rows(?string $host, string $env, string $kubectl): array
    {
        $tool = $this->tool();
        $aliasHosts = $this->getToolAliasHosts($kubectl, $tool);

        $rows = [[
            $tool->getLabel(),
            $host !== null
                ? "https://{$host}"
                : "<fg=gray>host not configured — run {$tool->initCommand()} {$env}</>",
        ]];

        foreach ($aliasHosts as $aliasHost) {
            $rows[] = [
                "{$tool->getLabel()} (Alias)",
                "https://{$aliasHost}",
            ];
        }

        return $rows;
    }

    /** Hook for post-table guidance (first-login steps, credential hints). */
    protected function afterTable(?string $host, string $env): void {}

    /**
     * Registry first (what was actually deployed), then .larakube.json, then
     * the conventional derivation. Deliberately never prompts — `:show` is a
     * read-only inspection command and must be safe to pipe.
     */
    protected function resolveHost(ClusterTool $tool, string $env, string $kubectl): ?string
    {
        $stored = $this->getToolHost($kubectl, $tool);
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $service = $tool->service();
        if ($service === null) {
            return null;
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        if ($config === null) {
            return null;
        }

        $pinned = $config->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($pinned !== null && $pinned !== '') {
            return $pinned;
        }

        $webHost = $config->getEnvironment($env)?->hosts['web'] ?? null;

        return $webHost ? $config->getSharedServiceHost($service, $env) : null;
    }

    /** Read one Secret key from the cluster — for tools that show bootstrap credentials. */
    protected function secretValue(string $kubectl, string $namespace, string $secret, string $key): ?string
    {
        $escaped = str_replace('.', '\\.', $key);
        $value = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$namespace} -o jsonpath='{.data.{$escaped}}' --ignore-not-found",
        )->output());

        if ($value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }
}
