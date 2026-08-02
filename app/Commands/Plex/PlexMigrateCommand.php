<?php

namespace App\Commands\Plex;

use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

use Laravel\Prompts\Prompt;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PlexMigrateCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:migrate
        {environment? : Environment whose data to migrate to the Commons — "local" (default) or a cloud environment. Omit to be prompted.}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved target, or the current context for local)}
        {--keep-pvc : Keep the self-hosted PVC(s) after migration (don\'t delete them)}';

    protected $description = 'Copy this project\'s self-hosted database and/or object storage into the shared Commons, then join';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube CLI Plex — Migrate data to the Commons');

        // Same fix as PlexJoinCommand — confirm()/multiselect() below are raw
        // Laravel\Prompts calls that decide interactivity from STDIN's TTY
        // status, not from this Symfony console option.
        if ($this->option('no-interaction')) {
            Prompt::interactive(false);
        }

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (! $config) {
            return 1;
        }

        $env = $this->resolvePlexEnvironment($config);

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

        // ── Idempotency guard: skip whatever a PREVIOUS run already finished ────
        // markServicesMigrated() (Step 5, below) marks each migrated service
        // `managed` for this env — that's the durable "already migrated" record.
        // Without this, re-running after getting stuck/interrupted (e.g. on the
        // PVC-deletion prompt) would re-pause the app and re-dump/re-restore
        // data that's already safely in the Commons. NOTE: a service being
        // already-managed only skips its COPY — $driver/$storage stay non-null
        // so Step 6 (PVC cleanup) below still runs for it; "already migrated"
        // must never mean "can't clean up a leftover PVC anymore".
        $managed = $config->getManaged($env);
        $skipDbCopy = $dbService !== null && in_array($dbService, $managed, true);
        $skipStorageCopy = $storageService !== null && in_array($storageService, $managed, true);

        if ($skipDbCopy) {
            $this->line("  <fg=gray>Database already Commons-managed for '{$env}' — will only check for a leftover PVC.</>");
        }
        if ($skipStorageCopy) {
            $this->line("  <fg=gray>Object storage already Commons-managed for '{$env}' — will only check for a leftover PVC.</>");
        }

        $needsCopy = (! $skipDbCopy && $dbService !== null) || (! $skipStorageCopy && $storageService !== null);

        $appName = $config->getName();
        $tenant = $this->plexTenantIdentifier($appName, $env);
        $namespace = $config->getNamespace($env);
        $dbPvc = $dbService !== null ? "{$appName}-{$driver->value}-pvc" : null;
        $storagePvc = $storageService !== null ? "{$appName}-{$storage->value}-pvc" : null;

        // ── Resolve context ───────────────────────────────────────────────────
        // --context always wins — the only way to avoid silently targeting
        // whatever kubectl's current context happens to be, which a
        // concurrently-running tool can flip out from under you.

        $override = (string) ($this->option('context') ?: '');

        if ($override !== '') {
            $context = $override;
        } elseif ($env === 'local') {
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

        $selfHostedKubectl = $this->plexKubectl();

        foreach (array_filter([$skipDbCopy ? null : $driver?->value, $skipStorageCopy ? null : $storage?->value]) as $podName) {
            $exists = trim(Process::run(
                $selfHostedKubectl.' get deploy '.escapeshellarg($podName).
                ' -n '.escapeshellarg($namespace).' -o name',
            )->output()) !== '';

            if (! $exists) {
                $this->laraKubeError("No '{$podName}' deployment found in '{$namespace}'.");
                $this->laraKubeLine('  Run `larakube up` (or deploy) first so the source service is running.');

                return 1;
            }
        }

        $dbPvcExists = $dbPvc !== null && trim(Process::run(
            $selfHostedKubectl.' get pvc '.escapeshellarg($dbPvc).
            ' -n '.escapeshellarg($namespace).' -o name',
        )->output()) !== '';

        $storagePvcExists = $storagePvc !== null && trim(Process::run(
            $selfHostedKubectl.' get pvc '.escapeshellarg($storagePvc).
            ' -n '.escapeshellarg($namespace).' -o name',
        )->output()) !== '';

        if (! $needsCopy && ! $dbPvcExists && ! $storagePvcExists) {
            $this->laraKubeInfo("Already migrated, and no leftover PVCs — nothing left to do for '{$env}'.");

            return 0;
        }

        // ── Confirm ───────────────────────────────────────────────────────────

        if ($needsCopy) {
            $this->newLine();
            if ($driver !== null && ! $skipDbCopy) {
                $this->laraKubeLine("  <fg=gray>Source:</> <fg=cyan>{$driver->getLabel()}</> in <fg=cyan>{$namespace}</>");
                $this->laraKubeLine("  <fg=gray>Target:</> Commons {$driver->getLabel()} in <fg=cyan>{$this->plexNamespace()}</> (tenant: <fg=cyan>{$tenant}</>)");
            }
            if ($storage !== null && ! $skipStorageCopy) {
                $this->laraKubeLine("  <fg=gray>Source:</> <fg=cyan>{$storage->getLabel()}</> in <fg=cyan>{$namespace}</>");
                $this->laraKubeLine("  <fg=gray>Target:</> Commons {$storage->getLabel()} in <fg=cyan>{$this->plexNamespace()}</> (bucket: <fg=cyan>".$this->plexBucketName($tenant).'</>)');
            }
            $this->newLine();
            $this->laraKubeWarn('This will COPY data from the self-hosted pod(s) to the Commons. The original pod(s) stay running until you approve deletion.');

            // Defaults to proceed: copying is non-destructive to the source
            // (nothing is deleted here), and invoking this command at all is
            // already a strong signal of intent — so under --no-interaction
            // this auto-proceeds instead of auto-aborting.
            if (! confirm('Proceed with migration?', true)) {
                return 0;
            }

            // ── Step 1: Ensure Commons offers what we need ─────────────────────

            if (! $this->ensureCommons(array_values(array_filter([
                $skipDbCopy ? null : $dbService,
                $skipStorageCopy ? null : $storageService,
            ])))) {
                return 1;
            }

            // ── Step 2: Allocate this tenant's slot in the Commons ─────────────
            // DB: temporary password; plex:join --yes (called at the end) re-runs
            // the idempotent SQL with a fresh password and saves it to .env.
            // Kept in $tempDbPassword (not thrown away) — Step 4's restore needs
            // it to connect AS the tenant role, not admin.
            // Storage: the bucket, under the shared Commons S3 credentials.

            $tempDbPassword = bin2hex(random_bytes(16));
            if ($driver !== null && ! $skipDbCopy && ! $this->allocateDatabase($driver, $tenant, $tempDbPassword)) {
                return 1;
            }

            $s3Creds = null;
            $bucket = null;
            if ($storage !== null && ! $skipStorageCopy) {
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
            if ($storage !== null && ! $skipStorageCopy) {
                $commonsHost = "{$storageService}.".$this->plexNamespace().".svc.cluster.local:{$storage->port()}";
                $mirrorCmd = $storage->selfHostedMirrorCommand($bucket, $commonsHost, $s3Creds['access'], $s3Creds['secret']);

                if ($mirrorCmd === null) {
                    $this->laraKubeError("No mirror command available for '{$storage->getLabel()}'.");

                    return 1;
                }
            }

            // ── Step 3: Quiesce writes, then copy from the self-hosted pod(s) ──
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
                if ($driver !== null && ! $skipDbCopy) {
                    $dumpFile = tempnam(sys_get_temp_dir(), 'larakube_plex_dump');

                    $this->withSpin('Dumping data from self-hosted database...', function () use ($selfHostedKubectl, $namespace, $driver, $dumpFile, &$dumpCode) {
                        $dumpCode = Process::run(
                            $selfHostedKubectl.' exec -n '.escapeshellarg($namespace).' deploy/'.escapeshellarg($driver->value).
                            ' -- sh -c '.escapeshellarg($driver->selfHostedDumpCommand()).
                            ' > '.escapeshellarg($dumpFile),
                        )->exitCode();

                        return $dumpCode === 0;
                    });
                }

                if ($storage !== null && ! $skipStorageCopy) {
                    $this->withSpin("Mirroring files into Commons bucket '{$bucket}'...", function () use ($selfHostedKubectl, $namespace, $storage, $mirrorCmd, &$mirrorOutput, &$mirrorCode) {
                        $result = Process::run(
                            $selfHostedKubectl.' exec -n '.escapeshellarg($namespace).' deploy/'.escapeshellarg($storage->value).
                            ' -- sh -c '.escapeshellarg($mirrorCmd),
                        );
                        $mirrorCode = $result->exitCode();
                        $mirrorOutput = explode("\n", trim($result->output().$result->errorOutput()));

                        return $mirrorCode === 0;
                    });
                }
            } finally {
                $this->resumeAppDeployments($selfHostedKubectl, $namespace, $originalReplicas);
            }

            if ($driver !== null && ! $skipDbCopy && ($dumpCode !== 0 || ! file_exists($dumpFile) || filesize($dumpFile) === 0)) {
                if ($dumpFile !== null) {
                    @unlink($dumpFile);
                }
                $this->laraKubeError("Dump from self-hosted {$driver->getLabel()} failed.");

                return 1;
            }

            if ($storage !== null && ! $skipStorageCopy && $mirrorCode !== 0) {
                // The self-hosted bucket never existing (never created via the
                // post-install instructions) isn't a real failure — there's no
                // data to lose either way, so skip it rather than abort the
                // whole migrate. Anything else IS a real failure.
                if ($this->mirrorFailedBecauseBucketMissing($mirrorOutput)) {
                    $this->laraKubeInfo("Self-hosted {$storage->getLabel()} has no bucket yet — nothing to migrate for storage, skipping.");
                } else {
                    if ($dumpFile !== null) {
                        @unlink($dumpFile);
                    }
                    $this->laraKubeError("Mirroring self-hosted {$storage->getLabel()} into the Commons failed.");
                    foreach (array_slice($mirrorOutput, -6) as $line) {
                        $this->laraKubeLine('    '.$line);
                    }

                    return 1;
                }
            }

            if ($driver !== null && ! $skipDbCopy) {
                $dumpSize = number_format(filesize($dumpFile) / 1024, 1).' KB';
                $this->line("  <fg=gray>Dump size:</> {$dumpSize}");
            }

            // ── Step 4: Restore the database dump to Commons ───────────────────
            // (Storage has no separate restore step — the mirror above copied
            // straight into the Commons bucket.)

            if ($driver !== null && ! $skipDbCopy) {
                $restoreOutput = [];
                $restoreCode = 0;
                $ns = $this->plexNamespace();

                $this->withSpin("Restoring data into Commons tenant '{$tenant}'...", function () use ($ns, $driver, $tenant, $tempDbPassword, $dumpFile, &$restoreOutput, &$restoreCode) {
                    $result = Process::run(
                        $this->plexKubectl().' exec -i -n '.escapeshellarg($ns).' deploy/'.$driver->value.
                        ' -- sh -c '.escapeshellarg($driver->commonsRestoreCommand($tenant, $tempDbPassword)).
                        ' < '.escapeshellarg($dumpFile),
                    );
                    $restoreCode = $result->exitCode();
                    $restoreOutput = explode("\n", trim($result->output().$result->errorOutput()));

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
        }

        // ── Step 5: Mark managed so plex:join's guard passes ────────────────────

        $this->markServicesMigrated($projectPath, $config, $env, array_values(array_filter([$dbService, $storageService])));

        // ── Step 6: Optional PVC deletion ───────────────────────────────────────

        $pvcTargets = [];
        if ($driver !== null && $dbPvcExists) {
            $pvcTargets[] = ['label' => $driver->getLabel(), 'pvc' => $dbPvc, 'deployment' => $driver->value];
        }
        if ($storage !== null && $storagePvcExists) {
            $pvcTargets[] = ['label' => $storage->getLabel(), 'pvc' => $storagePvc, 'deployment' => $storage->value];
        }

        if (! empty($pvcTargets)) {
            $this->newLine();
            foreach ($pvcTargets as $target) {
                $this->laraKubeWarn("PVC '{$target['pvc']}' in '{$namespace}' still holds the old {$target['label']} data.");
            }

            // Which PVCs to actually delete, identified by PVC name (a plain
            // list array with an integer `default` confuses Laravel Prompts'
            // list-vs-associative handling — keying by name avoids that AND
            // reads naturally as the thing being selected). --keep-pvc, or
            // running non-interactively at all, keeps everything — this is
            // the one truly irreversible step in the whole command, so it
            // NEVER auto-deletes just because prompts are disabled; it needs a
            // real human to pick which ones and type the app name to confirm.
            if ($this->option('keep-pvc') || $this->option('no-interaction')) {
                $selected = [];
            } else {
                $options = [];
                foreach ($pvcTargets as $target) {
                    $options[$target['pvc']] = "{$target['label']} ({$target['pvc']})";
                }

                $picked = multiselect(
                    label: 'Which self-hosted PVC(s) should be deleted? (data is already safe in the Commons)',
                    options: $options,
                    default: array_keys($options),
                    hint: 'Space to toggle · deselect any you want to keep as a rollback safety net.',
                );

                if (empty($picked)) {
                    $selected = [];
                } else {
                    $confirm = text(
                        label: "Type the project name '{$appName}' to permanently delete the selected PVC(s):",
                        required: true,
                    );

                    $selected = $confirm === $appName ? $picked : [];

                    if ($confirm !== $appName) {
                        $this->laraKubeInfo('Confirmation failed — no PVCs deleted.');
                    }
                }
            }

            foreach ($pvcTargets as $target) {
                if (! in_array($target['pvc'], $selected, true)) {
                    $this->line("  <fg=gray>Kept — delete manually once verified:</>  <fg=yellow>kubectl delete pvc {$target['pvc']} -n {$namespace}</>");

                    continue;
                }

                // Its own pod may still be mounting it — releaseSelfHostedPvc()
                // scales the deployment to 0 and polls if a plain delete
                // doesn't finish immediately, rather than hanging on the
                // pvc-protection finalizer with zero feedback. Safe
                // regardless: this service is confirmed migrated and approved
                // for deletion, and gets dropped from the local overlay
                // entirely on the next heal+up anyway.
                $released = false;
                $this->withSpin("Releasing '{$target['pvc']}'...", function () use ($selfHostedKubectl, $namespace, $target, &$released) {
                    $released = $this->releaseSelfHostedPvc($selfHostedKubectl, $namespace, $target['pvc'], $target['deployment']);
                });

                if (! $released) {
                    $this->laraKubeWarn("'{$target['pvc']}' is still Terminating — its pod may need a moment longer, or run `larakube up` to fully remove it.");
                } else {
                    $this->line("  <fg=gray>Deleted:</> {$target['pvc']}");
                }
            }
        }

        // ── Step 7: Complete the join (allocation + .env config + heal) ────────

        $this->newLine();
        $this->laraKubeInfo('Running plex:join to finalise credentials and manifests…');
        $this->newLine();

        // Forced non-interactive regardless of how THIS command was run — the
        // service list was already decided by what we just migrated, so
        // re-asking join's own service picker here would just be redundant.
        // --context is forwarded explicitly so join targets the same cluster
        // this run resolved to, rather than re-resolving it.
        $joinCode = $this->call('plex:join', array_filter([
            'environment' => $env,
            '--context' => $context,
            '--no-interaction' => true,
        ]));

        if ($joinCode !== 0) {
            $this->laraKubeWarn('plex:join did not complete cleanly. Run `larakube plex:join '.$env.'` manually.');
        }

        return $joinCode;
    }

    /**
     * Whether an `mc mirror` failure was simply because the self-hosted
     * source bucket doesn't exist yet (e.g. never created via the storage
     * driver's post-install instructions) — nothing to migrate, not a real
     * failure. `mc` reports this as e.g. "Unable to stat source `src/laravel`.
     * Bucket `laravel` does not exist."
     *
     * @param  array<int, string>  $output
     */
    protected function mirrorFailedBecauseBucketMissing(array $output): bool
    {
        foreach ($output as $line) {
            if (stripos($line, 'does not exist') !== false) {
                return true;
            }
        }

        return false;
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
        $decoded = json_decode(Process::run(
            "{$kubectl} get deployments -n ".escapeshellarg($namespace).' -o json',
        )->output(), true);

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
                Process::run("{$kubectl} scale deployment/{$name} --replicas=0 -n ".escapeshellarg($namespace));
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
                Process::run("{$kubectl} scale deployment/{$name} --replicas={$replicas} -n ".escapeshellarg($namespace));
            }

            return true;
        });
    }
}
