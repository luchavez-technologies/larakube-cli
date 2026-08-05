<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Base for every `{tool}:remove {environment}` command.
 *
 * Replaces the `{tool}:init --remove` flag that used to hide teardown inside
 * the install command. Two reasons that was wrong, beyond the mental map:
 * `--remove` silently ignored every install-shaped flag next to it
 * (`{tool}:init --remove --domain=x` looked meaningful and wasn't), and the
 * install and teardown paths shared a signature but no code, so they drifted.
 *
 * What this base owns — identical for all 24 tools, previously copy-pasted:
 *   environment → kube-context resolution, the destructive confirmation, the
 *   Plex Commons database drop, the Commons Redis index release, unregistering
 *   from the cluster tool registry, and honest exit codes.
 *
 * What a subclass owns: `tool()`, and `teardown()` for the resource list only.
 * Concrete teardowns are moved across verbatim from the old `remove*()` methods
 * because those encode real, hard-won fixes (see DeploysClusterTool's docblock)
 * that a generic "delete everything labelled X" rewrite would quietly lose.
 */
abstract class AbstractToolRemoveCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithPlex, LaraKubeOutput, RequiresFlagsWhenNonInteractive, SyncsClusterSecrets;

    public function __construct()
    {
        $tool = $this->tool();

        if (empty($this->signature)) {
            $this->signature = "{$tool->value}:remove
            {environment=local : Environment to remove ".$tool->getLabel()." from}
            {--context=  : Target a specific kube-context (defaults to the environment's saved cloud target)}
            {--instance=main : Named instance identifier (default: main)}
            {--purge     : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.}
            {--force     : Skip the confirmation prompt (required for non-interactive runs)}";
        }

        $this->description ??= "Remove {$tool->getLabel()} from a cluster";

        parent::__construct();
    }

    public function handle(): int
    {
        $this->renderHeader();

        $tool = $this->tool();
        $env = (string) $this->argument('environment');
        $instance = (string) ($this->option('instance') ?: 'main');

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        // InteractsWithPlex talks to the Commons through its own kubectl; point
        // it at the same cluster or we'd drop a tenant from the WRONG Commons.
        $this->plexContext = $context;

        // contextKubectl() pins KUBECONFIG to ~/.kube/config — every tool's own
        // *Kubectl() helper did this, and a bare `kubectl` would silently follow
        // an ambient $KUBECONFIG to a different cluster than the one we resolved.
        $kubectl = $this->contextKubectl($context);
        $namespace = $tool->namespace();
        $isPurging = (bool) $this->option('purge');

        if (! $this->confirmDestructive($this->teardownWarning($env))) {
            return 0;
        }

        $ok = true;

        if ($isPurging) {
            $ok = $this->dropCommonsTenants($kubectl, $instance) && $ok;
        }

        $ok = $this->teardown($kubectl, $namespace) && $ok;

        if (! $ok) {
            $this->laraKubeError(
                "One or more {$tool->getLabel()} resources failed to remove — "
                .'check kubectl access to the cluster above and re-run.',
            );

            return 1;
        }

        $this->unregisterTool($kubectl, $tool, $instance);

        if ($isPurging) {
            $this->laraKubeInfo("{$tool->getLabel()} removed from {$namespace} in '{$env}' (Commons database destroyed).");
        } else {
            $this->laraKubeInfo("{$tool->getLabel()} removed from {$namespace} in '{$env}'.");
            $this->line('  <fg=gray>Note:</> Persistent data (Plex Commons DB + S3 buckets) was preserved.');
            $this->line("  To restore, re-run <fg=blue>larakube {$tool->value}:init</>. To destroy data, re-run with <fg=yellow>--purge</>.");
        }

        return 0;
    }

    /** The tool this command tears down. */
    abstract protected function tool(): ClusterTool;

    /**
     * Delete this tool's Kubernetes resources. Return false on any failed step
     * so the command exits non-zero instead of printing a false "removed".
     */
    abstract protected function teardown(string $kubectl, string $namespace): bool;

    /**
     * The red block shown before teardown. Subclasses override to name the
     * specific workloads/volumes at risk.
     *
     * @return list<string>
     */
    protected function teardownWarning(string $env): array
    {
        $tool = $this->tool();
        $isPurging = (bool) $this->option('purge');

        $lines = [
            "{$tool->getLabel()} will be REMOVED from '{$env}':",
            "Deployments, Services, Ingresses and Secrets in {$tool->namespace()}",
        ];

        if ($isPurging && $tool->commonsDatabases() !== []) {
            $lines[] = 'Plex Commons database(s) WILL BE DESTROYED: '.implode(', ', $tool->commonsDatabases());
        } else {
            $lines[] = 'Persistent data (Plex Commons DB + S3 buckets) WILL BE PRESERVED.';
        }

        return $lines;
    }

    /**
     * Drop this tool's Commons Postgres tenant(s) and release any Commons Redis
     * index. Skipped per-database when the install bundled its own storage
     * (`--no-plex`) — detected by the subclass via usesBundledStorage(), because
     * dropping a Commons database that this install never leased would destroy
     * a DIFFERENT tool's data if the names ever collided.
     */
    protected function dropCommonsTenants(string $kubectl, string $instance = 'main'): bool
    {
        $tool = $this->tool();
        $databases = $tool->commonsDatabases($instance);

        if ($databases === [] || $this->usesBundledStorage($kubectl, $tool->namespace())) {
            return true;
        }

        $ok = true;
        $plexNs = $this->plexNamespace();
        $client = DatabaseDriver::POSTGRESQL->commonsAdminClient();

        foreach ($databases as $database) {
            $sql = $this->buildDropTenantSql($database, $database);
            $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop_'.$tool->value);
            file_put_contents($tmp, $sql);

            $ok = $this->removeResources(
                "Dropping database '{$database}' from Plex Commons (if exists)...",
                "{$kubectl} exec -i -n {$plexNs} deploy/postgres -- sh -c "
                .escapeshellarg($client).' < '.escapeshellarg($tmp),
            ) && $ok;

            $this->deleteStaticRole($kubectl, $database);

            $this->unregisterTenant($database);

            @unlink($tmp);
        }

        $this->unregisterTenant($instance === 'main' ? $tool->value : "{$tool->value}_{$instance}");

        foreach ($tool->commonsRedisKeys() as $key) {
            $redisTenant = $instance === 'main' ? $key : "{$key}_{$instance}";
            $this->releaseCommonsRedisIndex($redisTenant);
        }

        return $ok;
    }

    /**
     * True when this install bundled its own database instead of leasing a
     * Commons tenant, so there is no Commons tenant to drop. Default: never
     * bundled. Tools with `--no-plex` override this with the same probe their
     * old remove path used (looking for the bundled DB Deployment / a secret).
     */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return false;
    }

    /** Shared helper for the common "does this Deployment exist" bundled-storage probe. */
    protected function deploymentExists(string $kubectl, string $namespace, string $deployment): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$namespace} --no-headers --ignore-not-found",
        )->output()) !== '';
    }
}
