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
            {--keep-data : Leave the Plex Commons database and storage in place — remove workloads only}
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

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        // InteractsWithPlex talks to the Commons through its own kubectl; point
        // it at the same cluster or we'd drop a tenant from the WRONG Commons.
        $this->plexContext = $context;

        // contextKubectl() pins KUBECONFIG to ~/.kube/config — every tool's own
        // *Kubectl() helper did this, and a bare `kubectl` would silently follow
        // an ambient $KUBECONFIG to a different cluster than the one we resolved.
        $kubectl = $this->contextKubectl($context);
        $namespace = $tool->namespace();

        if (! $this->confirmDestructive($this->teardownWarning($env))) {
            return 0;
        }

        $ok = true;

        if (! $this->option('keep-data')) {
            $ok = $this->dropCommonsTenants($kubectl) && $ok;
        }

        $ok = $this->teardown($kubectl, $namespace) && $ok;

        if (! $ok) {
            $this->laraKubeError(
                "One or more {$tool->getLabel()} resources failed to remove — "
                .'check kubectl access to the cluster above and re-run.',
            );

            return 1;
        }

        $this->unregisterTool($kubectl, $tool);

        $this->laraKubeInfo("{$tool->getLabel()} removed from {$namespace} in '{$env}'.");

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

        $lines = [
            "{$tool->getLabel()} will be REMOVED from '{$env}':",
            "Deployments, Services, Ingresses and Secrets in {$tool->namespace()}",
        ];

        if (! $this->option('keep-data') && $tool->commonsDatabases() !== []) {
            $lines[] = 'Plex Commons database(s): '.implode(', ', $tool->commonsDatabases());
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
    protected function dropCommonsTenants(string $kubectl): bool
    {
        $tool = $this->tool();
        $databases = $tool->commonsDatabases();

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

            // The role being dropped above is exactly what OpenBao's static
            // role (if this tool ever ran secrets:wire or its own :init
            // registered one) points at. Left registered, a later :init that
            // recreates the role idempotently no-ops registerStaticRole()'s
            // POST — OpenBao then keeps enforcing its stale cached password
            // against the freshly-created Postgres user, silently reverting
            // whatever the fresh install set. Confirmed live 2026-08-02 on
            // Zitadel: came back up fine, desynced again ~40 minutes later.
            $this->deleteStaticRole($kubectl, $database);

            $this->unregisterTenant($database);

            @unlink($tmp);
        }

        $this->unregisterTenant($tool->value);

        foreach ($tool->commonsRedisKeys() as $key) {
            $this->releaseCommonsRedisIndex($key);
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
