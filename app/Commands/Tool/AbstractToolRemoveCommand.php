<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Enums\DatabaseDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolHost;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

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
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithPlex, LaraKubeOutput, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesToolHost, SyncsClusterSecrets;

    /** The instance the teardown loop is currently removing; null outside handle(). */
    protected ?string $currentInstance = null;

    public function __construct()
    {
        $tool = $this->tool();

        if (empty($this->signature)) {
            $this->signature = "{$tool->value}:remove
            {environment=local : Environment to remove ".$tool->getLabel()." from}
            {--context=  : Target a specific kube-context (defaults to the environment's saved cloud target)}
            {--domain=   : The instance's host, to target a specific one (e.g. --domain=blog.example.com). Omit for the default instance}
            {--all       : Remove all registered instances of this tool}
            {--purge     : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.}
            {--force     : Skip the confirmation prompt (required for non-interactive runs)}";
        }

        $this->description ??= "Remove {$tool->getLabel()} from a cluster";

        parent::__construct();
    }

    public function handle(): int
    {
        $tool = $this->tool();

        if ($this->refuseUnshippedTool($tool)) {
            return 1;
        }

        $this->renderHeader();

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
        $isPurging = (bool) $this->option('purge');

        // Every instance serving the targeted host — normally exactly one
        // ('main' when --domain is omitted). A host registered under MORE
        // than one instance is a duplicate-registration artifact (the DATA
        // incident of 2026-08-09): removal means "take down everything
        // serving this host", so all matching instances go.
        $targets = $this->resolveInstanceTargets($kubectl);
        foreach ($targets as $targetInstance) {
            if ($targetInstance !== 'main' && ! $tool->supportsMultipleInstances()) {
                $this->laraKubeError(
                    "{$tool->getLabel()} does not support multiple instances — ".
                    '--domain would silently do nothing (or worse, a misleading partial removal) since its '.
                    'teardown targets fixed resource names. Omit --domain to remove the single installation.',
                );

                return 1;
            }
        }

        if (! $this->confirmDestructive($this->teardownWarning($env))) {
            return 0;
        }

        $ok = true;

        foreach ($targets as $targetInstance) {
            $this->currentInstance = $targetInstance;

            if ($isPurging) {
                $ok = $this->dropCommonsTenants($kubectl, $targetInstance) && $ok;
            }

            $ok = $this->teardown($kubectl, $namespace) && $ok;

            $this->unregisterTool($kubectl, $tool, $targetInstance);
        }
        $this->currentInstance = null;

        if (! $ok) {
            $this->laraKubeError(
                "One or more {$tool->getLabel()} resources failed to remove — "
                .'check kubectl access to the cluster above and re-run.',
            );

            return 1;
        }

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
     * Which instance(s) to remove.
     * 1. --domain given → target instances registered for that domain/host.
     * 2. --all given → target every registered instance of this tool.
     * 3. Interactive with multiple registered instances → prompt select choice.
     * 4. Single/unregistered → default to registered instance or 'main'.
     *
     * @return list<string>
     */
    protected function resolveInstanceTargets(string $kubectl): array
    {
        $domain = (string) ($this->option('domain') ?: '');
        if ($domain !== '') {
            return $this->resolveInstanceTargetsForDomain($kubectl, $this->tool(), $domain);
        }

        if ($this->hasOption('all') && $this->option('all')) {
            $instances = $this->getToolInstances($kubectl, $this->tool());

            return $instances !== [] ? $instances : ['main'];
        }

        $tool = $this->tool();
        $registered = array_values(array_filter(
            $this->getRegisteredTools($kubectl),
            fn (array $e) => ($e['tool'] ?? null) === $tool->value,
        ));

        if (count($registered) > 1 && ! $this->cannotPrompt()) {
            $options = [];
            foreach ($registered as $entry) {
                $inst = (string) ($entry['instance'] ?? 'main');
                $host = (string) ($entry['host'] ?? '');
                $label = $host !== '' ? "{$inst} ({$host})" : $inst;
                $options[$inst] = $label;
            }
            $options['__all__'] = 'All instances';

            $choice = select(
                label: "Which {$tool->getLabel()} instance would you like to remove?",
                options: $options,
            );

            if ($choice === '__all__') {
                return array_values(array_filter(array_keys($options), fn ($k) => $k !== '__all__'));
            }

            return [$choice];
        }

        if ($registered !== []) {
            return [(string) ($registered[0]['instance'] ?? 'main')];
        }

        return ['main'];
    }

    /**
     * The instance the current teardown step is targeting — the loop
     * instance when handle() runs over several (duplicate-host removal),
     * otherwise the first (derived/default) target.
     */
    protected function resolveInstance(string $kubectl): string
    {
        if ($this->currentInstance !== null) {
            return $this->currentInstance;
        }

        return $this->resolveInstanceTargets($kubectl)[0];
    }

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

    /**
     * Build a single `kubectl delete` command deleting every component's
     * Deployment plus its declared companion resources — the generic form
     * of what compound tools' teardown() used to hand-copy independently of
     * the Blade manifest that actually deploys them. Every resource carries
     * `--ignore-not-found`, so a component that doesn't exist for this
     * install (e.g. a --no-plex-only bundled component on a Plex-backed
     * install) is silently skipped rather than needing its own condition
     * here — same discipline the hand-written strings this replaces relied on.
     */
    protected function teardownComponentsCommand(string $kubectl, string $namespace, string $instance = 'main'): string
    {
        $refs = [];
        foreach ($this->tool()->components($instance) as $component) {
            $refs[] = "deployment/{$component->deployment}";
            foreach ($component->resources as $resource) {
                $refs[] = "{$resource['kind']}/{$resource['name']}";
            }
        }

        return "{$kubectl} delete ".implode(' ', $refs)." -n {$namespace} --ignore-not-found";
    }
}
