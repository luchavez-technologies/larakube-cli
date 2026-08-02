<?php

namespace App\Commands\Plex;

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PlexLeaveCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:leave
        {environment? : Environment to remove from the Commons — "local" (default) or a cloud environment. Omit to be prompted.}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved target, or the current context for local)}
        {--backup= : Path for the pre-drop pg_dump backup (default: ./<tenant>-commons-<env>.sql)}
        {--no-backup : Skip the safety backup before dropping (dangerous)}
        {--restore : Phase 2 — copy Commons data into the now-live self-hosted pod(s), then finish leaving}
        {--force : Skip the name confirmation}';

    protected $description = 'Remove this project from the shared Commons (drops its tenant database/role)';

    /**
     * Leaving is two phases, because the self-hosted pod(s) don't even exist
     * yet when you first decide to leave (they were dropped from the
     * manifests when you joined):
     *
     *   1. `plex:leave {env}` — clears the plex/managed markers + regenerates
     *      manifests so self-hosted service(s) come back. Touches NOTHING on
     *      the Commons side yet. Tells you to redeploy.
     *   2. `plex:leave {env} --restore` (after redeploying) — verifies the
     *      self-hosted pod(s) are actually Ready, copies the Commons data
     *      INTO them, and only once that succeeds does the destructive
     *      Commons-side drop/flush/delete + registry removal happen. Same
     *      "never destroy the source until the copy is verified" rule
     *      plex:migrate follows, just in the opposite direction.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Leave the Commons');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        if (! $config) {
            return 1;
        }

        $env = $this->resolvePlexEnvironment($config);
        if ($env === 'local') {
            $this->laraKubeWarn('You are leaving Plex in a local environment.');
            $this->line('  <fg=gray>Local Plex commons data is ephemeral and lost on <fg=yellow>larakube down</>.</>');
            $this->newLine();

            if (! confirm('Continue anyway?', false)) {
                return 0;
            }
        }

        $appName = $config->getName();
        $tenant = $this->plexTenantIdentifier($appName, $env);

        // Target the env's own Commons context (no switching); fall back to the
        // current context if no deploy target is recorded. --context always wins.
        $context = $this->contextOverrideOr($config, $env);
        $this->plexContext = $context;

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($context ? "context '{$context}'" : 'current context').' is unreachable.');

            return 1;
        }

        // Tenant must be registered in this Commons.
        $registry = $this->getRegistry();
        if (! isset($registry['tenants'][$tenant])) {
            $this->laraKubeInfo("'{$tenant}' is not a tenant of this Commons — nothing to do.");

            return 0;
        }

        $entry = $registry['tenants'][$tenant];
        $db = $entry['db'] ?? null;
        $redisIndex = $entry['redis_index'] ?? null;
        $s3Bucket = $entry['s3_bucket'] ?? null;
        $s3Service = $entry['s3_service'] ?? 'seaweedfs';
        // Which engine holds the tenant DB — legacy entries predate db_service,
        // so default to Postgres (the only Commons backend back then).
        $dbDriver = DatabaseDriver::tryFrom($entry['db_service'] ?? 'postgres') ?? DatabaseDriver::POSTGRESQL;
        $namespace = $config->getNamespace($env);
        $storage = $config->getObjectStorage();

        $this->line("  <fg=gray>Tenant:</> <fg=cyan>{$tenant}</>  <fg=gray>env:</> <fg=cyan>{$env}</>  <fg=gray>context:</> <fg=cyan>".($context ?: 'current').'</>');

        // Like plex:join's "migrate now?" — if the self-hosted service(s) are
        // ALREADY back and Ready (you already ran step 1 + redeployed, maybe
        // in an earlier run), proactively offer to finish right now instead
        // of requiring you to remember the --restore flag exists.
        $shouldRestore = (bool) $this->option('restore');

        if (! $shouldRestore) {
            $targets = array_values(array_filter([
                $db ? $dbDriver->value : null,
                $s3Bucket && $storage !== null ? $storage->value : null,
            ]));

            if (! empty($targets) && $this->allSelfHostedReady($targets, $namespace)) {
                $this->laraKubeInfo('Self-hosted service(s) already look up and Ready.');
                $shouldRestore = confirm('Restore the Commons data now and finish leaving?', true);
            }
        }

        return $shouldRestore
            ? $this->finishLeaving($config, $env, $tenant, $appName, $namespace, $registry, $db, $dbDriver, $redisIndex, $s3Bucket, $s3Service)
            : $this->prepareToLeave($config, $projectPath, $env, $tenant, $appName, $db, $dbDriver, $redisIndex, $s3Bucket);
    }

    /**
     * Phase 1: warn what leaving will eventually do, then clear the
     * plex/managed markers and regenerate manifests so the self-hosted
     * service(s) come back. The Commons itself is untouched.
     */
    protected function prepareToLeave(
        ConfigData $config,
        string $projectPath,
        string $env,
        string $tenant,
        string $appName,
        ?string $db,
        DatabaseDriver $dbDriver,
        ?int $redisIndex,
        ?string $s3Bucket,
    ): int {
        $this->laraKubeWarn('Leaving will eventually PERMANENTLY drop this tenant from the Commons:');
        if ($db) {
            $this->laraKubeLine("    • {$dbDriver->getLabel()} database \"{$db}\" and login \"{$tenant}\" (all data)");
        }
        if ($redisIndex !== null) {
            $this->laraKubeLine("    • Redis logical DB {$redisIndex} (flushed)");
        }
        if ($s3Bucket) {
            $this->laraKubeLine("    • Object-storage bucket \"{$s3Bucket}\" (all objects)");
        }
        $this->laraKubeLine('    • the tenant entry in the Commons registry');
        $this->laraKubeLine('  ...but NOT yet. This step only brings the self-hosted service(s) back; the');
        $this->laraKubeLine('  Commons is only touched (and only after copying its data back) in step 2.');
        $this->laraKubeNewLine();

        if (! $this->option('force')) {
            $confirm = text(label: "To confirm, type the app name '{$appName}':", required: true);
            if ($confirm !== $appName) {
                $this->laraKubeError('Name mismatch. Leave aborted.');

                return 1;
            }
        }

        $this->clearPlexMarkers($projectPath, $config, $env);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('Regenerating manifests so the self-hosted service(s) come back...');
        if ($this->callSilent('heal', ['--force' => true]) !== 0) {
            $this->laraKubeWarn('Could not auto-regenerate manifests. Run `larakube heal --force` yourself.');
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Step 1 done — nothing on the Commons has been touched yet.');
        $this->line('  Next:');
        $this->line('    1. '.($env === 'local'
            ? '<fg=yellow>larakube up</>'
            : "<fg=yellow>larakube cloud:deploy {$env}</>").' — bring the self-hosted service(s) back online.');
        $this->line("    2. Once it's Ready: <fg=yellow>larakube plex:leave {$env} --restore</> — copies the Commons data back, then finishes leaving.");

        return 0;
    }

    /**
     * Phase 2: verify the self-hosted pod(s) exist and are Ready, copy the
     * Commons data INTO them, then (only once that succeeds) do the
     * destructive Commons-side drop/flush/delete + registry removal.
     */
    protected function finishLeaving(
        ConfigData $config,
        string $env,
        string $tenant,
        string $appName,
        string $namespace,
        array $registry,
        ?string $db,
        DatabaseDriver $dbDriver,
        ?int $redisIndex,
        ?string $s3Bucket,
        string $s3Service,
    ): int {
        $ns = $this->plexNamespace();
        $storage = $config->getObjectStorage();

        // Nowhere to restore into unless the self-hosted pod(s) are actually
        // up — verify before touching anything.
        $targets = array_filter([
            $db ? $dbDriver->value : null,
            $s3Bucket && $storage !== null ? $storage->value : null,
        ]);

        foreach ($targets as $podName) {
            if (! $this->deployReady($podName, $namespace)) {
                $this->laraKubeError("Self-hosted '{$podName}' isn't Ready yet in '{$namespace}'.");
                $this->laraKubeLine('  Redeploy first ('.($env === 'local' ? 'larakube up' : "larakube cloud:deploy {$env}").') and wait for it to come up, then retry.');

                return 1;
            }
        }

        if ($this->appStillDeployed($config, $env)) {
            $this->laraKubeWarn("Heads up: '{$appName}' still appears deployed in '{$namespace}'.");
            $this->laraKubeLine('  Its pods may still be pointed at the Commons mid-restore — consider tearing it down first.');
            $this->laraKubeNewLine();
        }

        if (! $this->option('force')) {
            $this->laraKubeWarn('This will copy the Commons data back, then PERMANENTLY drop this tenant from the Commons.');
            $confirm = text(label: "To confirm, type the app name '{$appName}':", required: true);
            if ($confirm !== $appName) {
                $this->laraKubeError('Name mismatch. Leave aborted.');

                return 1;
            }
        }

        // 1. Restore the database Commons -> self-hosted.
        if ($db && ! $this->restoreDatabaseToSelfHosted($namespace, $ns, $dbDriver, $db)) {
            $this->laraKubeError('Restoring the database into the self-hosted pod failed — aborting before touching the Commons.');

            return 1;
        }

        // 2. Restore object storage Commons -> self-hosted.
        if ($s3Bucket && $storage !== null && ! $this->restoreStorageToSelfHosted($namespace, $ns, $storage, $s3Bucket)) {
            $this->laraKubeError('Restoring object storage into the self-hosted pod failed — aborting before touching the Commons.');

            return 1;
        }

        if ($db || $s3Bucket) {
            $this->laraKubeInfo('Commons data copied back to the self-hosted service(s).');
        }

        // 3. Safety backup of the Commons copy before dropping it — an
        //    independent artifact even after the restore above.
        if ($db && ! $this->option('no-backup')) {
            $backupPath = $this->option('backup') ?: getcwd()."/{$tenant}-commons-{$env}.sql";
            if (! $this->backupTenantDatabase($ns, $dbDriver, $db, $backupPath)) {
                $this->laraKubeError('Backup failed — aborting before any destructive change. Re-run with --no-backup to skip (dangerous).');

                return 1;
            }
            $this->line("  <fg=gray>Backed up to</> {$backupPath}");
        }

        // 4. Drop the tenant's database + login from its Commons engine.
        if ($db && ! $this->dropTenantDatabase($ns, $dbDriver, $db, $tenant)) {
            return 1;
        }

        // 5. Flush the tenant's Redis logical DB (best-effort — index is freed
        //    by the registry removal regardless).
        if ($redisIndex !== null) {
            $this->withSpin("Flushing Redis db {$redisIndex}...", fn () => Process::run(
                $this->plexKubectl().' exec -n '.escapeshellarg($ns)." deploy/redis -- redis-cli -n {$redisIndex} FLUSHDB",
            ));
        }

        // 6. Delete the tenant's S3 bucket from the Commons (best-effort) —
        //    now that its contents are safely mirrored back.
        $s3Driver = StorageDriver::tryFrom($s3Service);
        if ($s3Bucket && $s3Driver !== null) {
            $cmd = $s3Driver->commonsBucketDeleteCommand($s3Bucket);
            $this->withSpin("Deleting object-storage bucket '{$s3Bucket}'...", fn () => Process::run(
                $this->plexKubectl().' exec -n '.escapeshellarg($ns).' deploy/'.$s3Service.' -- sh -c '.escapeshellarg($cmd),
            ));
        }

        // 7. Remove the tenant from the Commons registry (frees its redis index).
        $this->saveRegistry($this->registryRemove($registry, $tenant));
        $this->line("  <fg=gray>Removed</> {$tenant} <fg=gray>from the Commons registry.</>");

        $this->printNext($env, $appName);

        return 0;
    }

    /**
     * Whether every given self-hosted deployment already has a Ready replica
     * — used to proactively offer --restore's behavior the moment it's
     * actually possible, without requiring the flag to be remembered.
     *
     * @param  array<int, string>  $podNames
     */
    protected function allSelfHostedReady(array $podNames, string $namespace): bool
    {
        foreach ($podNames as $podName) {
            if (! $this->deployReady($podName, $namespace)) {
                return false;
            }
        }

        return true;
    }

    /** Whether a deployment has at least one Ready replica in the namespace. */
    protected function deployReady(string $podName, string $namespace): bool
    {
        $ready = trim(Process::run(
            $this->plexKubectl().' get deploy '.escapeshellarg($podName).
            ' -n '.escapeshellarg($namespace)." -o jsonpath='{.status.readyReplicas}'",
        )->output());

        return $ready !== '' && (int) $ready >= 1;
    }

    /**
     * Copy the Commons tenant database into the self-hosted pod's own
     * "laravel" database — dump from Commons to a local staging file (also
     * doubles as an artifact if anything goes wrong), then restore into the
     * self-hosted pod. Mirrors plex:migrate's dump/restore, reversed.
     */
    protected function restoreDatabaseToSelfHosted(string $namespace, string $ns, DatabaseDriver $driver, string $db): bool
    {
        $dumpFile = tempnam(sys_get_temp_dir(), 'larakube_plex_leave_dump');
        $dumpCode = 0;

        $this->withSpin("Dumping tenant database '{$db}' from the Commons...", function () use ($ns, $driver, $db, $dumpFile, &$dumpCode) {
            $dumpCode = Process::run(
                $this->plexKubectl().' exec -n '.escapeshellarg($ns).' deploy/'.$driver->value.
                ' -- sh -c '.escapeshellarg($driver->commonsBackupCommand($db)).
                ' > '.escapeshellarg($dumpFile),
            )->exitCode();

            return $dumpCode === 0;
        });

        if ($dumpCode !== 0 || ! file_exists($dumpFile) || filesize($dumpFile) === 0) {
            @unlink($dumpFile);

            return false;
        }

        $restoreCode = 0;
        $this->withSpin("Restoring into the self-hosted {$driver->getLabel()}...", function () use ($namespace, $driver, $dumpFile, &$restoreCode) {
            $restoreCode = Process::run(
                $this->plexKubectl().' exec -i -n '.escapeshellarg($namespace).' deploy/'.$driver->value.
                ' -- sh -c '.escapeshellarg($driver->selfHostedRestoreCommand()).
                ' < '.escapeshellarg($dumpFile),
            )->exitCode();

            return $restoreCode === 0;
        });

        @unlink($dumpFile);

        return $restoreCode === 0;
    }

    /**
     * Pull a Commons tenant bucket's contents back into the self-hosted
     * "laravel" bucket. Backends without a migrate path (isMigratable()
     * false) can't be automated — warns and lets leaving continue rather
     * than blocking on a backend we have no way to copy.
     */
    protected function restoreStorageToSelfHosted(string $namespace, string $ns, StorageDriver $storage, string $bucket): bool
    {
        if (! $storage->isMigratable()) {
            $this->laraKubeWarn("'{$storage->getLabel()}' has no restore path yet — pull bucket '{$bucket}' back manually before it's dropped from the Commons.");

            return true;
        }

        $creds = $this->readCommonsS3Credentials();
        if ($creds === null) {
            $this->laraKubeError('Commons S3 credentials (plex-admin) not found.');

            return false;
        }

        $commonsHost = "{$storage->commonsServiceName()}.{$ns}.svc.cluster.local:{$storage->port()}";
        $cmd = $storage->commonsToSelfHostedMirrorCommand($bucket, $commonsHost, $creds['access'], $creds['secret']);

        if ($cmd === null) {
            return false;
        }

        $code = 0;
        $this->withSpin("Mirroring bucket '{$bucket}' back to the self-hosted {$storage->getLabel()}...", function () use ($namespace, $storage, $cmd, &$code) {
            $code = Process::run(
                $this->plexKubectl().' exec -n '.escapeshellarg($namespace).' deploy/'.$storage->value.
                ' -- sh -c '.escapeshellarg($cmd),
            )->exitCode();

            return $code === 0;
        });

        return $code === 0;
    }

    /**
     * Whether the app still has deployments in its env namespace (a hint to tear
     * the app down before dropping the data it points at).
     */
    protected function appStillDeployed(ConfigData $config, string $env): bool
    {
        return trim(Process::run(
            $this->plexKubectl().' get deploy -n '.escapeshellarg($config->getNamespace($env)).' -o name',
        )->output()) !== '';
    }

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

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop');
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

        @unlink($tmp);

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
     * Drop the env's plex marker and remove the Commons services from its managed
     * list, so a later heal/regenerate stops treating them as Commons-backed.
     */
    protected function clearPlexMarkers(string $projectPath, ConfigData $config, string $env): void
    {
        $plexServices = $config->getPlex($env);
        if (empty($plexServices)) {
            return;
        }

        $data = $config->toArray();
        $data['environments'][$env]['managed'] = array_values(array_diff(
            $data['environments'][$env]['managed'] ?? [],
            $plexServices,
        ));
        $data['environments'][$env]['plex'] = [];
        ConfigData::from($data)->saveToFile($projectPath);

        $this->line('  <fg=gray>Cleared plex/managed markers in .larakube.json for:</> '.implode(', ', $plexServices));
    }

    protected function printNext(string $env, string $appName): void
    {
        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ '{$appName}' has left the Commons.");
        $this->line('  The app is fully self-hosted again, with its Commons data restored.');
    }
}
