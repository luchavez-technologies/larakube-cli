<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

/**
 * Preflight guard for multi-node state.
 *
 * On multi-node, LaraKube gives each app pod per-pod ephemeral storage (emptyDir)
 * so pods spread across nodes instead of fighting over one ReadWriteOnce volume.
 * That makes anything kept on the local filesystem **per-pod and lost on restart**
 * — so multi-node requires externalized state: uploads on S3, sessions/cache off
 * `file` (Laravel's `database` default is fine; Redis works too). SQLite can't go
 * multi-node at all (its DB is a file on an RWO volume).
 *
 * We warn LOUD here rather than let a deploy silently lose data. The using class
 * must also use LaraKubeOutput, ResolvesEnvironmentContext (for clusterNodeCount),
 * and define a `--force` option (cloud:deploy, heal).
 */
trait GuardsSharedStorage
{
    /**
     * Returns true to proceed, false to abort.
     *
     * @param  string|null  $context  Cluster context to probe node count (deploy
     *                                time). Null (e.g. heal) → judge by strategy.
     */
    protected function guardSharedStorage(ConfigData $config, string $environment, ?string $context = null): bool
    {
        if (! $this->isMultiNode($config, $environment, $context)) {
            return true;
        }

        // SQLite can't span nodes — its DB file lives on an RWO volume.
        if ($config->hasDatabase(DatabaseDriver::SQLITE)) {
            $this->laraKubeWarn("⚠ Multi-node + SQLite — '{$environment}'");
            $this->line('  SQLite stores its database in a file on a ReadWriteOnce volume, which cannot be');
            $this->line('  shared across nodes. This environment will not work multi-node as-is.');
            $this->newLine();
            $this->line('  <fg=green>Fix:</> use a networked database (Postgres / MySQL / MariaDB) for multi-node,');
            $this->line('  or set this env to <fg=yellow>strategy: single-node</> on a 1-node pool.');
            $this->newLine();

            return $this->allowStorageOverride();
        }

        // Opted into shared (RWX) storage → state is shared across nodes, so no
        // per-pod data-loss concern. Just make sure the NFS provisioner is there.
        if ($config->usesSharedStorage($environment)) {
            if ($context !== null && $context !== '' && ! $this->nfsStorageClassPresent($context)) {
                $this->laraKubeWarn("⚠ '{$environment}' uses shared storage, but the NFS provisioner isn't installed.");
                $this->line('  The shared ReadWriteMany PVC will stay Pending until you run');
                $this->line('  <fg=yellow>larakube cloud:init:nfs</> on this cluster.');
                $this->newLine();

                return $this->allowStorageOverride();
            }

            return true;
        }

        // Anything still on local storage is per-pod ephemeral on multi-node.
        $risky = $this->localStateDrivers($config, $environment);
        if ($risky === []) {
            return true; // uploads on object storage, sessions/cache off `file` → safe
        }

        $this->laraKubeWarn("⚠ Multi-node uses per-pod ephemeral storage — '{$environment}'");
        $this->line('  App pods spread across nodes with their own ephemeral disk, so local files are');
        $this->line('  neither shared between pods nor kept across restarts. Still on local storage:');
        foreach ($risky as $r) {
            $this->line("    • <fg=yellow>{$r}</>");
        }
        $this->newLine();
        $this->line('  <fg=green>Externalize, then redeploy:</>');
        $this->line('   • uploads → object storage (FILESYSTEM_DISK=s3) — `larakube plex:join` gives a bucket');
        $this->line('   • sessions/cache → redis or database (not `file`)');
        $this->newLine();

        // Offer to do it for them rather than just warn — the easy path becomes the
        // right path. Skipped under --force / --no-interaction (handled below).
        if (! $this->option('force') && ! $this->option('no-interaction')
            && confirm('Externalize these for you now?', default: true)) {
            $this->call('cloud:externalize', ['environment' => $environment]);

            // Re-read the freshly written .env.{env}; continue if it's all externalized.
            if ($this->localStateDrivers($config, $environment) === []) {
                $this->laraKubeInfo('✅ Externalized — continuing the deploy.');

                return true;
            }
            $this->laraKubeWarn('Some state is still on local storage.');
            $this->newLine();
        }

        return $this->allowStorageOverride();
    }

    /** Multi-node when the strategy says so, or a probed cluster has more than one node. */
    protected function isMultiNode(ConfigData $config, string $environment, ?string $context): bool
    {
        if ($config->getStrategy($environment) === DeploymentStrategy::MULTI_NODE_HA) {
            return true;
        }

        return $context !== null && $context !== '' && $this->clusterNodeCount($context) > 1;
    }

    /**
     * State drivers in .env.{env} that still point at the local filesystem and so
     * would be lost/unshared on multi-node. Empty when the env file is absent
     * (can't inspect → don't block) or everything is externalized.
     *
     * @return array<int, string>
     */
    protected function localStateDrivers(ConfigData $config, string $environment): array
    {
        $envFile = $config->getPath().'/.env.'.$environment;
        if (! is_file($envFile)) {
            return [];
        }

        $vars = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $vars[trim($key)] = trim($value, " \t\"'");
        }

        $risky = [];
        $disk = $vars['FILESYSTEM_DISK'] ?? 'local';
        if (! in_array(strtolower($disk), ['s3', 'minio', 'spaces'], true)) {
            $risky[] = "FILESYSTEM_DISK={$disk} (uploads not on object storage)";
        }
        if (($vars['SESSION_DRIVER'] ?? 'database') === 'file') {
            $risky[] = 'SESSION_DRIVER=file';
        }
        if (($vars['CACHE_STORE'] ?? $vars['CACHE_DRIVER'] ?? 'database') === 'file') {
            $risky[] = 'CACHE_STORE=file';
        }

        return $risky;
    }

    /** Is the larakube-nfs StorageClass (cloud:init:nfs) installed on this cluster? */
    protected function nfsStorageClassPresent(string $context): bool
    {
        return Process::run(
            $this->contextKubectl($context).' get storageclass '.escapeshellarg(ConfigData::NFS_STORAGE_CLASS).' -o name',
        )->successful();
    }

    /**
     * Honor --force / --no-interaction, else ask. The using command must define a
     * `--force` option (cloud:deploy, heal do).
     */
    protected function allowStorageOverride(): bool
    {
        if ($this->option('force')) {
            $this->laraKubeWarn('Proceeding anyway (--force).');

            return true;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Aborting. Re-run with --force to override.');

            return false;
        }

        return confirm('Proceed anyway?', default: false);
    }
}
