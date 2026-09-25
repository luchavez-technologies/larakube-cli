<?php

namespace App\Commands\Plex;

use App\Enums\DatabaseDriver;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\RemovesPlexTenants;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Evicts a tenant from the Commons side, for when its project no longer exists
 * and plex:leave (which restores data into the project first) cannot run.
 * Destroys data with no restore step, so it is its own command, not a flag.
 */
class PlexEvictCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithPlex, InteractsWithProjectConfig,
        LaraKubeOutput, RemovesPlexTenants, RequiresFlagsWhenNonInteractive,
        ResolvesEnvironmentContext;

    protected $signature = 'plex:evict
        {environment? : Environment whose Commons to evict from — "local" (default) or a cloud environment. Omit to be prompted.}
        {--tenant= : The Commons tenant to evict (e.g. hello_app_local). Omit to pick from the registry.}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved target)}
        {--backup= : Path for the pre-drop database backup (default: ./<tenant>-commons.sql)}
        {--no-backup : Skip the safety backup before dropping (dangerous)}
        {--force : Skip the confirmation (and the tenant-still-deployed guard)}';

    protected $description = 'Evict an orphaned tenant from the Commons, freeing its database, bucket and Redis index';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Evict a tenant');

        // Run from anywhere: a project is optional here (that's the whole
        // point — the project is usually gone), so a null config just means
        // the environment can't contribute a saved deploy target and the
        // current context, or --context, is it.
        $config = $this->getProjectConfig(getcwd());
        $env = $this->resolvePlexEnvironment($config);

        $context = $env === 'local'
            ? ((string) $this->option('context') ?: null)
            : ($this->option('context') ?: ($config ? $this->environmentContextOrCurrent($config, $env) : null));

        $this->plexContext = $context !== '' ? $context : null;

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($this->plexContext !== null ? "context '{$this->plexContext}'" : 'current context').' is unreachable.');

            return 1;
        }

        $registry = $this->getRegistry();
        $tenants = $registry['tenants'] ?? [];

        if ($tenants === []) {
            $this->laraKubeInfo('This Commons has no registered tenants — nothing to evict.');

            return 0;
        }

        $tenant = $this->resolveTenant($tenants);

        if (! isset($tenants[$tenant])) {
            $this->laraKubeError("'{$tenant}' is not a tenant of this Commons.");
            $this->laraKubeLine('  <fg=gray>List the real ones with</> <fg=cyan>larakube plex:show</><fg=gray>.</>');

            return 1;
        }

        $entry = $tenants[$tenant];
        $ns = $this->plexNamespace();
        $db = $entry['db'] ?? null;
        $redisIndex = $entry['redis_index'] ?? null;
        $s3Bucket = $entry['s3_bucket'] ?? null;
        $s3Service = $entry['s3_service'] ?? 'seaweedfs';
        // Legacy entries predate db_service, so default to Postgres — the only
        // Commons engine that existed when they were written.
        $dbDriver = DatabaseDriver::tryFrom($entry['db_service'] ?? 'postgres') ?? DatabaseDriver::POSTGRESQL;

        $this->line("  <fg=gray>Tenant:</> <fg=cyan>{$tenant}</>  <fg=gray>env:</> <fg=cyan>{$env}</>  <fg=gray>context:</> <fg=cyan>".($this->plexContext ?? 'current').'</>');

        if (! $this->guardStillDeployed($tenant, $entry['namespace'] ?? null)) {
            return 1;
        }

        // Asked BEFORE the confirmation, not after: a registry entry can outlive
        // its database (an earlier --fresh join, a manual drop), and "the dump
        // failed" then blocked an eviction that had nothing left to destroy.
        // Knowing which case this is changes both what we print and whether a
        // failed dump is allowed to stop us.
        $dbExists = $db ? $this->tenantDatabaseExists($ns, $dbDriver, $db) : null;

        if ($db && $dbExists === false) {
            $this->laraKubeNewLine();
            $this->laraKubeWarn("There is no '{$db}' database in the Commons — nothing to back up.");
            $this->laraKubeLine('  <fg=gray>This registry entry outlived its database; LaraKube CLI is not deleting one here.</>');
            $this->laraKubeLine('  <fg=gray>The eviction clears the stale entry (and the login, if it still exists).</>');
        }

        $lines = ['Evicting will PERMANENTLY destroy, with NO restore step:'];
        foreach ($this->describeTenantAllocation($entry, $tenant, $dbExists) as $item) {
            $lines[] = "  • {$item}";
        }

        if (! $this->confirmDestructive($lines)) {
            return 0;
        }

        // Safety net: a dump is the only thing standing between a mistyped
        // tenant name and unrecoverable data, since — unlike plex:leave —
        // nothing was copied anywhere first. Skipped only when we positively
        // know there is no database; a null ("cannot tell") still backs up,
        // and a failed dump still aborts.
        if ($db && $dbExists !== false && ! $this->option('no-backup')) {
            $backupPath = (string) ($this->option('backup') ?: getcwd()."/{$tenant}-commons.sql");

            if (! $this->backupTenantDatabase($ns, $dbDriver, $db, $backupPath)) {
                $this->laraKubeError('Backup failed — aborting before any destructive change.');
                $this->laraKubeLine('  <fg=gray>Re-run with</> <fg=cyan>--no-backup</> <fg=gray>to evict anyway (dangerous).</>');

                return 1;
            }

            $this->line("  <fg=gray>Backed up to</> {$backupPath}");
        }

        if ($db && ! $this->dropTenantDatabase($ns, $dbDriver, $db, $tenant)) {
            return 1;
        }

        if ($redisIndex !== null) {
            $this->flushTenantRedis($ns, $redisIndex);
        }

        if ($s3Bucket) {
            $this->deleteTenantBucket($ns, $s3Service, $s3Bucket);
        }

        $registry = $this->registryRemove($this->getRegistry(), $tenant);
        $this->saveRegistry($registry);
        $this->line("  <fg=gray>Removed</> {$tenant} <fg=gray>from the Commons registry.</>");

        $this->laraKubeNewLine();
        $free = 16 - count($this->registryUsedRedisIndexes($registry));
        $this->laraKubeInfo("Evicted '{$tenant}'. Redis slots free: {$free}/16.");

        return 0;
    }

    /**
     * The tenant to evict: --tenant, or a picker over the registry. There is
     * no default — evicting the wrong tenant is unrecoverable, so a
     * non-interactive run without the flag fails instead of guessing.
     *
     * @param  array<string, array<string, mixed>>  $tenants
     */
    protected function resolveTenant(array $tenants): string
    {
        return $this->flagOrPrompt(
            'tenant',
            fn (): string => $this->pickTenant($tenants),
            'which Commons tenant to evict (see `larakube plex:show`)',
            'larakube plex:evict local --tenant='.(string) array_key_first($tenants),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $tenants
     */
    protected function pickTenant(array $tenants): string
    {
        $options = [];
        foreach ($tenants as $name => $alloc) {
            $holds = array_filter([
                ! empty($alloc['db']) ? 'db' : null,
                ($alloc['redis_index'] ?? null) !== null ? 'redis '.$alloc['redis_index'] : null,
                ! empty($alloc['s3_bucket']) ? 's3' : null,
            ]);

            $options[$name] = $name.($holds === [] ? '' : '  ('.implode(', ', $holds).')');
        }

        return select(
            label: 'Which tenant should be evicted from the Commons?',
            options: $options,
            scroll: 15,
            hint: 'This destroys its data. Use plex:leave instead if the project still exists.',
        );
    }

    /**
     * Refuse to evict a tenant whose namespace still holds workloads — those
     * pods are almost certainly still pointed at the Commons, and eviction
     * would break them mid-flight with no restore. plex:leave is the command
     * for that case; --force is the escape hatch.
     */
    protected function guardStillDeployed(string $tenant, ?string $namespace): bool
    {
        // A Cluster Tool's tenant carries no namespace, so the check below can
        // never speak for one. Ask the tool registry whether an installed
        // instance claims this tenant first — that is the only guard standing
        // between a mistyped --tenant and a live tool's database.
        $owner = $this->tenantOwner($tenant);

        if ($owner !== null && ! $this->option('force')) {
            $this->laraKubeNewLine();
            $this->laraKubeError("'{$tenant}' belongs to {$owner->tool->getLabel()} ({$owner->instance}), which is installed on this cluster.");
            $this->laraKubeLine('  <fg=gray>Evicting it would drop the database out from under a running tool.</>');
            $this->laraKubeLine("  <fg=gray>Remove the tool instead:</> <fg=cyan>larakube {$owner->tool->removeCommand()} --purge</><fg=gray>.</>");
            $this->laraKubeLine('  <fg=gray>Or pass</> <fg=cyan>--force</> <fg=gray>if you know this tenant is a leftover the tool no longer uses.</>');

            return false;
        }

        $inUse = $this->tenantNamespaceInUse($namespace);

        if ($inUse === true && ! $this->option('force')) {
            $this->laraKubeNewLine();
            $this->laraKubeError("'{$tenant}' still has workloads running in '{$namespace}'.");
            $this->laraKubeLine('  <fg=gray>Evicting now would strand them against a dropped database.</>');
            $this->laraKubeLine('  <fg=gray>From the project, run</> <fg=cyan>larakube plex:leave</> <fg=gray>— it restores the data first.</>');
            $this->laraKubeLine('  <fg=gray>Or pass</> <fg=cyan>--force</> <fg=gray>if you know the namespace is disposable.</>');

            return false;
        }

        if ($inUse === null) {
            $this->laraKubeWarn($namespace === null || $namespace === ''
                ? 'Could not verify whether anything still uses this tenant: its registry entry records no namespace to check.'
                : "Could not verify whether anything still uses this tenant: namespace '{$namespace}' no longer exists.");
        }

        return true;
    }
}
