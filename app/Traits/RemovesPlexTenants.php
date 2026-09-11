<?php

namespace App\Traits;

use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * The destructive half of removing a tenant from a Commons: back up, drop the
 * database + login, flush the Redis logical DB, delete the bucket.
 *
 * Shared by `plex:leave` (the project is present and its data is restored to
 * self-hosted pods first) and `plex:evict` (the project is gone, so there is
 * nothing to restore into). Both end in exactly the same Commons-side state,
 * which is the reason these steps live in one place rather than being written
 * twice: a divergence here leaks a tenant's database or its Redis index.
 */
trait RemovesPlexTenants
{
    /**
     * Dump the tenant database to a local file using the engine's own tool
     * (pg_dump / mysqldump, from the driver). Returns false (and writes no
     * destructive change) if the dump fails or is empty.
     */
    protected function backupTenantDatabase(string $ns, DatabaseDriver $driver, string $db, string $path): bool
    {
        $service = $driver->value;
        $cmd = $driver->commonsBackupCommand($db);
        $code = 0;
        $this->withSpin("Backing up database '{$db}'...", function () use ($ns, $service, $cmd, $path, &$code) {
            $code = Process::run(
                $this->plexKubectl().' exec -n '.escapeshellarg($ns).' deploy/'.$service.' -- '.
                'sh -c '.escapeshellarg($cmd).' > '.escapeshellarg($path),
            )->exitCode();

            return $code === 0;
        });

        return $code === 0 && file_exists($path) && filesize($path) > 0;
    }

    /**
     * Run the engine's drop SQL (DROP DATABASE + DROP login) in the Commons via
     * kubectl exec. SQL + admin client come from the DatabaseDriver enum.
     */
    protected function dropTenantDatabase(string $ns, DatabaseDriver $driver, string $db, string $tenant): bool
    {
        $sql = $driver->commonsDropSql($db, $tenant);
        if ($sql === null) {
            return true; // non-relational engine — nothing to drop.
        }

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/drop.sql';
        file_put_contents($tmp, $sql);

        $service = $driver->value;
        $client = $driver->commonsAdminClient();
        $output = [];
        $code = 0;
        $this->withSpin("Dropping database '{$db}' and login '{$tenant}'...", function () use ($ns, $service, $client, $tmp, &$output, &$code) {
            $result = Process::run(
                $this->plexKubectl().' exec -i -n '.escapeshellarg($ns).' deploy/'.$service.' -- '.
                'sh -c '.escapeshellarg($client).' < '.escapeshellarg($tmp),
            );
            $code = $result->exitCode();
            $output = explode("\n", trim($result->output().$result->errorOutput()));

            return $code === 0;
        });

        $temporaryDirectory->delete();

        if ($code !== 0) {
            $this->laraKubeError('Could not drop the tenant database/login from the Commons.');
            foreach (array_slice($output, -4) as $line) {
                $this->laraKubeLine('    '.$line);
            }

            return false;
        }

        return true;
    }

    /**
     * Flush the tenant's Redis logical DB. Best-effort: the index itself is
     * freed by removing the tenant from the registry, so a failure here leaves
     * stale keys behind for the next tenant to inherit, not a lost slot.
     */
    protected function flushTenantRedis(string $ns, int $redisIndex): void
    {
        $this->withSpin("Flushing Redis db {$redisIndex}...", fn () => Process::run(
            $this->plexKubectl().' exec -n '.escapeshellarg($ns)." deploy/redis -- redis-cli -n {$redisIndex} FLUSHDB",
        ));
    }

    /**
     * Delete the tenant's bucket from the Commons object store. Best-effort,
     * and a no-op when the recorded backend isn't a StorageDriver we know.
     */
    protected function deleteTenantBucket(string $ns, string $s3Service, string $bucket): void
    {
        $driver = StorageDriver::tryFrom($s3Service);

        if ($driver === null) {
            return;
        }

        $cmd = $driver->commonsBucketDeleteCommand($bucket);
        $this->withSpin("Deleting object-storage bucket '{$bucket}'...", fn () => Process::run(
            $this->plexKubectl().' exec -n '.escapeshellarg($ns).' deploy/'.$s3Service.' -- sh -c '.escapeshellarg($cmd),
        ));
    }

    /**
     * Whether the tenant's database is actually present in the Commons engine.
     *
     * Returns null for "cannot tell" — the engine ships no catalogue query, or
     * the query itself failed. Callers must read null as "assume it is there",
     * so a genuinely broken dump still blocks a destructive run; only a
     * definite false means there is nothing to back up.
     */
    protected function tenantDatabaseExists(string $ns, DatabaseDriver $driver, string $db): ?bool
    {
        $list = $driver->commonsListDatabasesCommand();

        if ($list === '') {
            return null;
        }

        $result = Process::run(
            $this->plexKubectl().' exec -n '.escapeshellarg($ns).' deploy/'.$driver->value.' -- '.
            'sh -c '.escapeshellarg($list),
        );

        if (! $result->successful()) {
            return null;
        }

        $names = array_filter(array_map('trim', explode("\n", trim($result->output()))));

        return in_array($db, $names, true);
    }

    /**
     * Human-readable list of what a registry entry still holds, for the
     * "this is what eviction destroys" confirmation.
     *
     * $dbExists === false rewrites the database line rather than dropping it:
     * the entry is still being destroyed, and silently omitting the database
     * would read as "we deleted it" to anyone reviewing the output later.
     *
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    protected function describeTenantAllocation(array $entry, string $tenant, ?bool $dbExists = null): array
    {
        $lines = [];
        $dbDriver = DatabaseDriver::tryFrom($entry['db_service'] ?? 'postgres') ?? DatabaseDriver::POSTGRESQL;

        if (! empty($entry['db'])) {
            $lines[] = $dbExists === false
                ? "{$dbDriver->getLabel()} login \"{$tenant}\" — its database \"{$entry['db']}\" is ALREADY GONE"
                : "{$dbDriver->getLabel()} database \"{$entry['db']}\" and login \"{$tenant}\" (all data)";
        }
        if (($entry['redis_index'] ?? null) !== null) {
            $lines[] = "Redis logical DB {$entry['redis_index']} (flushed, then freed for reuse)";
        }
        if (! empty($entry['s3_bucket'])) {
            $lines[] = "Object-storage bucket \"{$entry['s3_bucket']}\" (all objects)";
        }

        $lines[] = 'the tenant entry in the Commons registry';

        return $lines;
    }

    /**
     * Whether a tenant's recorded namespace still holds workloads — the only
     * evidence available that something might still be USING this tenant once
     * its project directory is gone.
     *
     * Registry entries written before `namespace` was recorded return null
     * ("cannot tell"), which callers must not read as "safe to evict".
     */
    protected function tenantNamespaceInUse(?string $namespace): ?bool
    {
        if ($namespace === null || $namespace === '') {
            return null;
        }

        $result = Process::run(
            $this->plexKubectl().' get deploy -n '.escapeshellarg($namespace).' -o name --ignore-not-found',
        );

        if (! $result->successful()) {
            return null; // namespace gone (or unreadable) — not proof of use.
        }

        return trim($result->output()) !== '';
    }
}
