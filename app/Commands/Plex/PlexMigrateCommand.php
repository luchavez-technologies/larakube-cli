<?php

namespace App\Commands\Plex;

use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class PlexMigrateCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:migrate
        {environment=production : The environment whose data to migrate to the Commons}
        {--keep-pvc : Keep the self-hosted PVC(s) after migration (don\'t delete them)}
        {--yes : Skip confirmation prompts}';

    protected $description = 'Copy this project\'s self-hosted database and/or object storage into the shared Commons, then join';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube CLI Plex — Migrate data to the Commons');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (! $config) {
            return 1;
        }

        $env = (string) $this->argument('environment');

        // Only relational drivers have data to migrate (SQLite is a local file).
        $driver = $config->getDatabase();
        if ($driver === DatabaseDriver::SQLITE) {
            $driver = null;
        }
        $dbService = $driver?->commonsServiceName();

        $storage = $config->getObjectStorage();
        $storageService = $storage?->commonsServiceName();

        if ($storage !== null && ! $storage->isMigratable()) {
            $this->laraKubeWarn("Object storage '{$storage->getLabel()}' has no migrate path yet — run plex:join separately and it'll get a fresh, empty bucket instead of a copy of your existing files.");
            $storage = null;
            $storageService = null;
        }

        if ($dbService === null && $storageService === null) {
            $this->laraKubeError('Nothing to migrate: no shareable database (MySQL/MariaDB/PostgreSQL) or migratable object storage found.');

            return 1;
        }

        $appName = $config->getName();
        $tenant = $this->plexTenantIdentifier($appName, $env);
        $namespace = $config->getNamespace($env);
        $dbPvc = $dbService !== null ? "{$appName}-{$driver->value}-pvc" : null;
        $storagePvc = $storageService !== null ? "{$appName}-{$storage->value}-pvc" : null;

        // ── Resolve context ───────────────────────────────────────────────────

        if ($env === 'local') {
            $context = null;
        } else {
            [$config, $context] = $this->resolveEnvironmentContext($config, $env, $projectPath);

            if (! $context) {
                $this->laraKubeError("No deploy target for '{$env}'. Run `larakube cloud:init` first.");

                return 1;
            }
        }

        $this->plexContext = $context;

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('Cluster is unreachable. Check the server or re-run cloud:init.');

            return 1;
        }

        // ── Verify source pod(s) running ──────────────────────────────────────

        $kubectl = $this->plexContext !== null
            ? 'kubectl --context '.escapeshellarg($this->plexContext)
            : 'kubectl';

        $selfHostedKubectl = ($env === 'local')
            ? 'kubectl'
            : 'kubectl --context '.escapeshellarg((string) $context);

        foreach (array_filter([$driver?->value, $storage?->value]) as $podName) {
            $exists = trim((string) shell_exec(
                $selfHostedKubectl.' get deploy '.escapeshellarg($podName).
                ' -n '.escapeshellarg($namespace).' -o name 2>/dev/null',
            )) !== '';

            if (! $exists) {
                $this->laraKubeError("No '{$podName}' deployment found in '{$namespace}'.");
                $this->laraKubeLine('  Run `larakube up` (or deploy) first so the source service is running.');

                return 1;
            }
        }

        $dbPvcExists = $dbPvc !== null && trim((string) shell_exec(
            $selfHostedKubectl.' get pvc '.escapeshellarg($dbPvc).
            ' -n '.escapeshellarg($namespace).' -o name 2>/dev/null',
        )) !== '';

        $storagePvcExists = $storagePvc !== null && trim((string) shell_exec(
            $selfHostedKubectl.' get pvc '.escapeshellarg($storagePvc).
            ' -n '.escapeshellarg($namespace).' -o name 2>/dev/null',
        )) !== '';

        // ── Confirm ───────────────────────────────────────────────────────────

        $this->newLine();
        if ($driver !== null) {
            $this->laraKubeLine("  <fg=gray>Source:</> <fg=cyan>{$driver->getLabel()}</> in <fg=cyan>{$namespace}</>");
            $this->laraKubeLine("  <fg=gray>Target:</> Commons {$driver->getLabel()} in <fg=cyan>larakube-shared</> (tenant: <fg=cyan>{$tenant}</>)");
        }
        if ($storage !== null) {
            $this->laraKubeLine("  <fg=gray>Source:</> <fg=cyan>{$storage->getLabel()}</> in <fg=cyan>{$namespace}</>");
            $this->laraKubeLine("  <fg=gray>Target:</> Commons {$storage->getLabel()} in <fg=cyan>larakube-shared</> (bucket: <fg=cyan>".$this->plexBucketName($tenant).'</>)');
        }
        $this->newLine();
        $this->laraKubeWarn('This will COPY data from the self-hosted pod(s) to the Commons. The original pod(s) stay running until you approve deletion.');

        if (! $this->option('yes') && ! confirm('Proceed with migration?', false)) {
            return 0;
        }

        // ── Step 1: Ensure Commons offers what we need ─────────────────────────

        if (! $this->ensureCommons(array_values(array_filter([$dbService, $storageService])))) {
            return 1;
        }

        // ── Step 2: Allocate this tenant's slot in the Commons ─────────────────
        // DB: temporary password; plex:join --yes (called at the end) re-runs
        // the idempotent SQL with a fresh password and saves it to .env.
        // Storage: the bucket, under the shared Commons S3 credentials.

        if ($driver !== null && ! $this->allocateDatabase($driver, $tenant, bin2hex(random_bytes(16)))) {
            return 1;
        }

        $s3Creds = null;
        $bucket = null;
        if ($storage !== null) {
            $s3Creds = $this->readCommonsS3Credentials();

            if ($s3Creds === null) {
                $this->laraKubeError('Commons S3 credentials (plex-admin) not found. Re-run `larakube plex:init`.');

                return 1;
            }

            $bucket = $this->plexBucketName($tenant);

            if (! $this->allocateStorageBucket($storage, $bucket)) {
                return 1;
            }
        }

        // Computed up front (before quiescing) so a broken config fails fast,
        // before the app is ever paused.
        $mirrorCmd = null;
        if ($storage !== null) {
            $commonsHost = "{$storageService}.".$this->plexNamespace().".svc.cluster.local:{$storage->port()}";
            $mirrorCmd = $storage->selfHostedMirrorCommand($bucket, $commonsHost, $s3Creds['access'], $s3Creds['secret']);

            if ($mirrorCmd === null) {
                $this->laraKubeError("No mirror command available for '{$storage->getLabel()}'.");

                return 1;
            }
        }

        // ── Step 3: Quiesce writes, then copy from the self-hosted pod(s) ──────
        // Scale every OTHER deployment in the namespace to zero so the copy is
        // consistent (no writes mid-copy) while the source service(s) stay up
        // to be read from. Resumed immediately after (success or fail) — the
        // app must never stay paused because of a migration hiccup. The source
        // data itself is never touched here.

        $originalReplicas = $this->quiesceAppDeployments(
            $selfHostedKubectl,
            $namespace,
            array_values(array_filter([$driver?->value, $storage?->value])),
        );

        $dumpFile = null;
        $dumpCode = 0;
        $mirrorCode = 0;
        $mirrorOutput = [];

        try {
            if ($driver !== null) {
                $dumpFile = tempnam(sys_get_temp_dir(), 'larakube_plex_dump');
                $dumpOutput = [];

                $this->withSpin('Dumping data from self-hosted database...', function () use ($selfHostedKubectl, $namespace, $driver, $dumpFile, &$dumpOutput, &$dumpCode) {
                    exec(
                        $selfHostedKubectl.' exec -n '.escapeshellarg($namespace).' deploy/'.escapeshellarg($driver->value).
                        ' -- sh -c '.escapeshellarg($driver->selfHostedDumpCommand()).
                        ' > '.escapeshellarg($dumpFile).' 2>/dev/null',
                        $dumpOutput,
                        $dumpCode,
                    );

                    return $dumpCode === 0;
                });
            }

            if ($storage !== null) {
                $this->withSpin("Mirroring files into Commons bucket '{$bucket}'...", function () use ($selfHostedKubectl, $namespace, $storage, $mirrorCmd, &$mirrorOutput, &$mirrorCode) {
                    exec(
                        $selfHostedKubectl.' exec -n '.escapeshellarg($namespace).' deploy/'.escapeshellarg($storage->value).
                        ' -- sh -c '.escapeshellarg($mirrorCmd).' 2>&1',
                        $mirrorOutput,
                        $mirrorCode,
                    );

                    return $mirrorCode === 0;
                });
            }
        } finally {
            $this->resumeAppDeployments($selfHostedKubectl, $namespace, $originalReplicas);
        }

        if ($driver !== null && ($dumpCode !== 0 || ! file_exists($dumpFile) || filesize($dumpFile) === 0)) {
            if ($dumpFile !== null) {
                @unlink($dumpFile);
            }
            $this->laraKubeError("Dump from self-hosted {$driver->getLabel()} failed.");

            return 1;
        }

        if ($storage !== null && $mirrorCode !== 0) {
            if ($dumpFile !== null) {
                @unlink($dumpFile);
            }
            $this->laraKubeError("Mirroring self-hosted {$storage->getLabel()} into the Commons failed.");
            foreach (array_slice($mirrorOutput, -6) as $line) {
                $this->laraKubeLine('    '.$line);
            }

            return 1;
        }

        if ($driver !== null) {
            $dumpSize = number_format(filesize($dumpFile) / 1024, 1).' KB';
            $this->line("  <fg=gray>Dump size:</> {$dumpSize}");
        }

        // ── Step 4: Restore the database dump to Commons ───────────────────────
        // (Storage has no separate restore step — the mirror above copied
        // straight into the Commons bucket.)

        if ($driver !== null) {
            $restoreOutput = [];
            $restoreCode = 0;
            $ns = $this->plexNamespace();

            $this->withSpin("Restoring data into Commons tenant '{$tenant}'...", function () use ($ns, $driver, $tenant, $dumpFile, &$restoreOutput, &$restoreCode) {
                exec(
                    $this->plexKubectl().' exec -i -n '.escapeshellarg($ns).' deploy/'.$driver->value.
                    ' -- sh -c '.escapeshellarg($driver->commonsRestoreCommand($tenant)).
                    ' < '.escapeshellarg($dumpFile).' 2>&1',
                    $restoreOutput,
                    $restoreCode,
                );

                return $restoreCode === 0;
            });

            @unlink($dumpFile);

            if ($restoreCode !== 0) {
                $this->laraKubeError("Restore into Commons {$driver->getLabel()} failed.");
                foreach (array_slice($restoreOutput, -6) as $line) {
                    $this->laraKubeLine('    '.$line);
                }

                return 1;
            }
        }

        $this->laraKubeInfo('Data migrated successfully.');

        // ── Step 5: Mark managed so plex:join's guard passes ────────────────────

        $this->markServicesMigrated($projectPath, $config, $env, array_values(array_filter([$dbService, $storageService])));

        // ── Step 6: Optional PVC deletion ───────────────────────────────────────

        $pvcTargets = [];
        if ($driver !== null && $dbPvcExists) {
            $pvcTargets[] = ['label' => $driver->getLabel(), 'pvc' => $dbPvc];
        }
        if ($storage !== null && $storagePvcExists) {
            $pvcTargets[] = ['label' => $storage->getLabel(), 'pvc' => $storagePvc];
        }

        if (! empty($pvcTargets) && ! $this->option('keep-pvc')) {
            $this->newLine();
            foreach ($pvcTargets as $target) {
                $this->laraKubeWarn("PVC '{$target['pvc']}' in '{$namespace}' still holds the old {$target['label']} data.");
            }

            if ($this->option('yes') || confirm('Delete the self-hosted PVC(s) now? (data is safely in the Commons)', false)) {
                foreach ($pvcTargets as $target) {
                    shell_exec($selfHostedKubectl.' delete pvc '.escapeshellarg($target['pvc']).' -n '.escapeshellarg($namespace).' 2>/dev/null');
                }
                $this->line('  <fg=gray>PVC(s) deleted.</>');
            } else {
                foreach ($pvcTargets as $target) {
                    $this->line("  <fg=gray>Kept — delete manually once verified:</>  <fg=yellow>kubectl delete pvc {$target['pvc']} -n {$namespace}</>");
                }
            }
        }

        // ── Step 7: Complete the join (allocation + .env config + heal) ────────

        $this->newLine();
        $this->laraKubeInfo('Running plex:join to finalise credentials and manifests…');
        $this->newLine();

        $joinCode = $this->call('plex:join', [
            'environment' => $env,
            '--yes' => true,
        ]);

        if ($joinCode !== 0) {
            $this->laraKubeWarn('plex:join did not complete cleanly. Run `larakube plex:join '.$env.' --yes` manually.');
        }

        return $joinCode;
    }

    /**
     * Scale every deployment in the namespace to zero EXCEPT the source
     * service(s) being copied from (which must stay up to be read) —
     * quiesces writes so the dump/mirror gets a consistent snapshot. Returns
     * the original replica counts (empty if nothing else runs there) so
     * resumeAppDeployments() can restore them afterwards.
     *
     * @param  array<int, string>  $excludeDeployments
     * @return array<string, int>
     */
    protected function quiesceAppDeployments(string $kubectl, string $namespace, array $excludeDeployments): array
    {
        $decoded = json_decode((string) shell_exec(
            "{$kubectl} get deployments -n ".escapeshellarg($namespace).' -o json 2>/dev/null',
        ), true);

        $original = [];
        foreach ($decoded['items'] ?? [] as $item) {
            $name = $item['metadata']['name'] ?? null;
            $replicas = $item['spec']['replicas'] ?? 1;

            if ($name === null || in_array($name, $excludeDeployments, true) || $replicas < 1) {
                continue;
            }

            $original[$name] = $replicas;
        }

        if (empty($original)) {
            return [];
        }

        $this->withSpin('Pausing app writes ('.implode(', ', array_keys($original)).')...', function () use ($kubectl, $namespace, $original) {
            foreach (array_keys($original) as $name) {
                shell_exec("{$kubectl} scale deployment/{$name} --replicas=0 -n ".escapeshellarg($namespace).' 2>/dev/null');
            }

            return true;
        });

        return $original;
    }

    /**
     * Restore the replica counts captured by quiesceAppDeployments(). Called
     * from a `finally` block so the app resumes whether the copy succeeded or
     * failed.
     *
     * @param  array<string, int>  $original
     */
    protected function resumeAppDeployments(string $kubectl, string $namespace, array $original): void
    {
        if (empty($original)) {
            return;
        }

        $this->withSpin('Resuming app...', function () use ($kubectl, $namespace, $original) {
            foreach ($original as $name => $replicas) {
                shell_exec("{$kubectl} scale deployment/{$name} --replicas={$replicas} -n ".escapeshellarg($namespace).' 2>/dev/null');
            }

            return true;
        });
    }
}
