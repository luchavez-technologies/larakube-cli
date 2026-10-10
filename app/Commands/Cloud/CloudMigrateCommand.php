<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\DatabaseDriver;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\QuiescesAppDeployments;
use App\Traits\ReadsCommandOptions;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Move an environment from its current cluster to a new one — orchestration
 * only, every actual step is an existing command this one already trusts:
 * cloud:create (--provision-managed), plex:export/plex:init (Commons
 * structure), backup:run/backup:restore (Commons data), dotenv:push
 * (secrets), cloud:configure --rebind (re-point the environment),
 * cloud:deploy (redeploy). See cli/plans/active/managed-kubernetes-desktop-
 * and-migration.md Phase C for the design this implements.
 *
 * After cloud:deploy succeeds on the destination, migrateOwnStorage() also
 * copies the project's OWN data that nothing else backs up: its SQLite file
 * or self-hosted (non-Commons, non-externally-managed) database (dump+
 * restore via the live `web`/`{driver}` pods' own `kubectl exec`, reusing
 * DatabaseDriver::selfHostedDumpCommand()/selfHostedRestoreCommand() —
 * exactly PlexMigrateCommand's/PlexLeaveCommand's own mechanism, just across
 * two contexts instead of one) and its local storage directory
 * (`storage/app/public`, or Bedrock's `web/app/uploads`) when 'storage'
 * isn't externally managed. This deliberately does NOT reuse backup:run's
 * throwaway-PVC-mount restore path — that path drops `subPath` when it
 * re-mounts a claim (see resolveVolumeClaim()), which would silently land
 * data in the wrong place for this PVC's several subPath-bound mounts; a
 * direct live-pod-to-live-pod `tar` pipe sidesteps that entirely, since
 * `kubectl exec` always sees the container's own (subPath-resolved)
 * filesystem view, on both ends. ownStorageItems() is also what populates
 * the pre-flight confirmation list.
 *
 * Deliberately NOT fully automatic:
 *   - DNS cutover is guidance only (inherited from cloud:deploy's own
 *     printIngressDnsGuidance() — no DNS-provider automation exists for this).
 *   - Commons volume restore stays "deliberately half-manual" per ADR 0010 —
 *     this command surfaces backup:restore's own printed instructions, never
 *     runs them for you.
 *   - The source cluster is never torn down — it may be shared with other
 *     projects.
 */
class CloudMigrateCommand extends Command
{
    use ConfirmsDestructiveAction, EmitsJsonOutput, InteractsWithBackup, InteractsWithEnvironments,
        InteractsWithProjectConfig, LaraKubeOutput, QuiescesAppDeployments, ReadsCommandOptions, ResolvesEnvironmentContext;

    protected $signature = 'cloud:migrate
        {environment? : The environment to migrate}
        {--to-context= : Kube-context of an already-provisioned destination cluster}
        {--provision-managed : Provision a brand-new managed Kubernetes cluster as the destination}
        {--provider= : Cloud provider for --provision-managed (do, gcp, aws)}
        {--region= : Provider region for --provision-managed}
        {--size= : Node size for --provision-managed}
        {--node-count= : Node count for --provision-managed}
        {--ha : Enable HA control plane for --provision-managed}
        {--k8s-version-prefix= : Managed Kubernetes minor version prefix for --provision-managed}
        {--quiesce : Pause background app deployments immediately before the Commons snapshot and the own-storage copy, shrinking the write-loss window}
        {--skip-dns-guidance : Suppress the printed DNS cutover reminder}
        {--force : Skip confirmation prompts}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Migrate an environment to a new (optionally brand-new managed) cluster — Commons, secrets, then redeploy';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $toContext = trim((string) $this->option('to-context'));
        $provisionManaged = (bool) $this->option('provision-managed');

        if ($toContext === '' && ! $provisionManaged) {
            $this->laraKubeError('Pass --to-context=<kube-context> (an existing cluster) or --provision-managed (create one).');

            return 1;
        }

        if ($toContext !== '' && $provisionManaged) {
            $this->laraKubeError('Pass only one of --to-context= or --provision-managed, not both.');

            return 1;
        }

        $environment = $this->argument('environment') ?: $this->askForCloudEnvironment(
            label: 'Which environment are you migrating?',
        );

        if ($environment === 'local') {
            $this->laraKubeError('Local is not a cloud environment — nothing to migrate.');

            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        [$config, $sourceContext] = $this->resolveEnvironmentContext($config, $environment, $projectPath);

        if ($sourceContext === null || $sourceContext === '') {
            $this->laraKubeError("'{$environment}' has no current deploy target to migrate from.");

            return 1;
        }

        $destinationContext = $toContext !== '' ? $toContext : $this->provisionDestination();

        if ($destinationContext === null) {
            return 1; // provisionDestination() already printed why.
        }

        if ($destinationContext === $sourceContext) {
            $this->laraKubeError("'{$environment}' already targets '{$destinationContext}' — nothing to migrate.");

            return 1;
        }

        $sourceKubectl = Kubectl::forContext($sourceContext)->prefix();
        $plexServices = $config->getPlex($environment);
        $ownItems = $this->ownStorageItems($config, $environment);

        if (! $this->confirmDestructive([
            "'{$environment}' moves from '{$sourceContext}' to '{$destinationContext}':",
            $plexServices !== [] ? 'Commons structure + data will be copied to the new cluster.' : 'This environment has no Commons to migrate.',
            $ownItems === [] ? "Nothing else of this project's own lives outside Commons." : ('Also copied after redeploy: '.implode(', ', array_column($ownItems, 'label')).'.'),
            'The source cluster is left running — tear it down yourself once you have verified the new one.',
        ])) {
            return 0;
        }

        if ($plexServices !== [] && ! $this->migrateCommons($config, $environment, $sourceContext, $destinationContext, $sourceKubectl)) {
            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo('Pushing secrets to the new cluster...');
        if ($this->call('dotenv:push', ['environment' => $environment, '--context' => $destinationContext, '--no-interaction' => true]) !== 0) {
            $this->laraKubeWarn('dotenv:push failed — fix it and re-run, or push secrets manually before deploying.');
        }

        $this->newLine();
        $this->laraKubeInfo("Re-pointing '{$environment}' at the new cluster...");
        if ($this->call('cloud:configure', [
            'environment' => $environment, '--only' => 'target', '--context' => $destinationContext, '--rebind' => true, '--no-interaction' => true,
        ]) !== 0) {
            $this->laraKubeError('Could not rebind the environment — see the output above. Nothing was deployed to the new cluster.');

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo('Redeploying to the new cluster...');
        $deployExit = $this->call('cloud:deploy', ['environment' => $environment, '--force' => true]);

        if ($deployExit !== 0) {
            $this->laraKubeError("cloud:deploy failed on the new cluster. '{$environment}' is now bound to '{$destinationContext}' — fix the deploy and re-run `larakube cloud:deploy {$environment}`.");

            return 1;
        }

        $ownResults = $ownItems !== []
            ? $this->migrateOwnStorage($config, $environment, $sourceKubectl, Kubectl::forContext($destinationContext)->prefix(), $ownItems)
            : [];

        $this->newLine();
        $this->laraKubeInfo("✅ '{$environment}' is migrated to '{$destinationContext}'.");
        $this->line("  <fg=gray>Verify it, then tear down the old cluster yourself when you're ready.</>");

        if (! $this->flag('skip-dns-guidance')) {
            $this->line('  <fg=yellow>•</> DNS still points at the old cluster — update it to the new ingress once you have verified the migration.');
        }

        $this->newLine();

        if ($this->flag('json')) {
            $this->jsonOutput([
                'success' => true,
                'environment' => $environment,
                'sourceContext' => $sourceContext,
                'destinationContext' => $destinationContext,
                'commonsMigrated' => $plexServices !== [],
                'ownStorageCopied' => array_keys(array_filter($ownResults)),
                'ownStorageFailed' => array_keys(array_filter($ownResults, fn (bool $ok): bool => ! $ok)),
            ]);
        }

        return 0;
    }

    /**
     * Provision a brand-new managed cluster via cloud:create, reusing its
     * entire provisioning pipeline rather than a second one — reads the
     * context back from its --json result. Null means it already errored.
     */
    private function provisionDestination(): ?string
    {
        $this->laraKubeInfo('Provisioning the destination cluster...');

        $forwarded = [
            '--provider' => $this->option('provider'),
            '--region' => $this->option('region'),
            '--size' => $this->option('size'),
            '--node-count' => $this->option('node-count'),
            '--k8s-version-prefix' => $this->option('k8s-version-prefix'),
        ];

        $args = array_filter($forwarded, fn ($value): bool => $value !== null && $value !== '');
        $args['--managed'] = true;
        $args['--json'] = true;
        $args['--no-interaction'] = true;

        if ($this->option('ha')) {
            $args['--ha'] = true;
        }

        // Artisan::call() (not $this->call(), which ignores a 3rd argument in
        // this framework) is the only way to capture a sub-command's output
        // into a buffer instead of letting it print to this command's own.
        $buffer = new BufferedOutput;
        $exit = Artisan::call('cloud:create', $args, $buffer);
        $lines = preg_split('/\R/', trim($buffer->fetch())) ?: [];
        $result = json_decode((string) end($lines), true);

        if ($exit !== 0 || ! is_array($result) || ($result['success'] ?? false) !== true || empty($result['context'])) {
            $this->laraKubeError('Could not provision the destination cluster: '.($result['error'] ?? 'see the output above.'));

            return null;
        }

        $this->laraKubeInfo("Destination cluster ready: '{$result['context']}'.");

        return (string) $result['context'];
    }

    /**
     * Commons structure (plex:export → plex:init --from=) then Commons data
     * (backup:run on the source → backup:restore per item on the
     * destination, reusing the same off-site destination backup:init already
     * configured — the bucket doesn't care which cluster is talking to it).
     * Volume restore stays whatever backup:restore itself does (half-manual,
     * per ADR 0010) — its own printed instructions stream straight through.
     */
    private function migrateCommons(ConfigData $config, string $environment, string $sourceContext, string $destinationContext, string $sourceKubectl): bool
    {
        $this->newLine();
        $this->laraKubeInfo('Rebuilding Commons structure on the new cluster...');

        $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
        $specFile = $directory->path('commons-spec.json');

        if ($this->call('plex:export', ['environment' => $environment, '--context' => $sourceContext, '--output' => $specFile]) !== 0) {
            $this->laraKubeError('plex:export failed — see the output above. Commons was not migrated.');

            return false;
        }

        if ($this->call('plex:init', [
            'environment' => $environment, '--context' => $destinationContext, '--from' => $specFile, '--no-interaction' => true,
        ]) !== 0) {
            $this->laraKubeError('plex:init --from failed on the destination — see the output above. Commons structure was not migrated.');

            return false;
        }

        $backupConfig = $this->readBackupConfig($sourceKubectl, $this->backupNamespace());

        if ($backupConfig === null) {
            $this->laraKubeWarn("No backup destination configured on the source cluster (run `larakube backup:init {$environment}`) — Commons STRUCTURE was rebuilt, but its DATA was not copied.");

            return true;
        }

        $namespace = $config->getNamespace($environment);

        $original = [];
        if ($this->flag('quiesce')) {
            $original = $this->quiesceAppDeployments($sourceKubectl, $namespace, []);
        }

        $this->laraKubeInfo('Snapshotting Commons data on the source cluster...');
        $buffer = new BufferedOutput;
        $runExit = Artisan::call('backup:run', ['environment' => $environment, '--context' => $sourceContext, '--json' => true], $buffer);

        if ($this->flag('quiesce')) {
            $this->resumeAppDeployments($sourceKubectl, $namespace, $original);
        }

        $lines = preg_split('/\R/', trim($buffer->fetch())) ?: [];
        $runResult = json_decode((string) end($lines), true);

        if ($runExit !== 0 || ! is_array($runResult) || ($runResult['success'] ?? false) !== true) {
            $this->laraKubeWarn('backup:run failed — Commons STRUCTURE was rebuilt, but its DATA was not copied. Fix the backup and copy it manually, or re-run this command.');

            return true;
        }

        $stamp = (string) ($runResult['backup']['id'] ?? '');
        $manifest = $stamp !== '' ? $this->fetchManifest($backupConfig, $stamp) : null;

        if ($manifest === null) {
            $this->laraKubeWarn("Could not read the backup manifest for '{$stamp}' — Commons data was snapshotted but not restored. Run `larakube backup:restore {$environment} --context={$destinationContext} --backup={$stamp}` manually.");

            return true;
        }

        $this->newLine();
        $this->laraKubeInfo('Restoring Commons data onto the new cluster...');

        foreach ($manifest['items'] as $item) {
            $flag = $item['kind'] === 'database' ? '--database' : '--volume';

            $this->line("  Restoring {$item['kind']} '{$item['name']}'...");

            $this->call('backup:restore', [
                'environment' => $environment,
                '--context' => $destinationContext,
                '--backup' => $stamp,
                $flag => $item['name'],
                '--endpoint' => $backupConfig['endpoint'],
                '--bucket' => $backupConfig['bucket'],
                '--access-key' => $backupConfig['access_key'],
                '--secret-key' => $backupConfig['secret_key'],
                '--passphrase' => $backupConfig['passphrase'],
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }

        return true;
    }

    /**
     * The project's own data that lives outside Commons/external management —
     * nothing backed this up before this command (confirmed:
     * InteractsWithBackup::larakubeNamespaces() only ever scans `larakube-*`
     * namespaces, never a project's own `{name}-{env}`). migrateOwnStorage()
     * copies every item this returns; this is also what the pre-flight
     * confirmation prompt lists.
     *
     * @return list<array{type: 'sqlite'|'database'|'storage', driver: ?DatabaseDriver, path: ?string, label: string}>
     */
    private function ownStorageItems(ConfigData $config, string $environment): array
    {
        $plex = $config->getPlex($environment);
        $managed = $config->getManaged($environment);
        $items = [];

        if ($config->hasDatabase(DatabaseDriver::SQLITE)
            && ! in_array(DatabaseDriver::SQLITE->value, $plex, true)
            && ! in_array(DatabaseDriver::SQLITE->value, $managed, true)
        ) {
            $items[] = ['type' => 'sqlite', 'driver' => null, 'path' => null, 'label' => 'SQLite data'];
        }

        foreach ($config->getDatabases() as $driver) {
            if ($driver === DatabaseDriver::SQLITE) {
                continue; // already covered above, with its own copy path.
            }

            if (in_array($driver->value, $plex, true) || in_array($driver->value, $managed, true)) {
                continue; // Commons-backed or externally managed — not this project's own storage.
            }

            $items[] = ['type' => 'database', 'driver' => $driver, 'path' => null, 'label' => "self-hosted {$driver->value} database"];
        }

        // Always true unless every byte lives in Commons/S3 — logs/cache/sessions
        // are rebuildable, but anything written here is not.
        if (! in_array('storage', $managed, true)) {
            $path = $config->framework === AppFramework::WORDPRESS ? 'web/app/uploads' : 'storage/app/public';
            $items[] = ['type' => 'storage', 'driver' => null, 'path' => $path, 'label' => "local {$path}"];
        }

        return $items;
    }

    /**
     * Copy every ownStorageItems() entry straight between the two clusters'
     * LIVE pods — dump/tar via `kubectl exec` on the source, pipe through one
     * local temp file, restore/untar via `kubectl exec -i` on the
     * destination (whose `cloud:deploy` has already created fresh, empty
     * PVCs and pods for this to land in). No throwaway helper pod and no raw
     * PVC mount is involved — see this class's own docblock for why that
     * matters for a `subPath`-mounted claim like this one. Never fatal: a
     * failed item is reported and the migration still completes, since
     * cloud:deploy already succeeded and the environment is live.
     *
     * @param  list<array{type: string, driver: ?DatabaseDriver, path: ?string, label: string}>  $items
     * @return array<string, bool> keyed by item label
     */
    private function migrateOwnStorage(ConfigData $config, string $environment, string $sourceKubectl, string $destinationKubectl, array $items): array
    {
        $this->newLine();
        $this->laraKubeInfo("Copying this project's own data to the new cluster...");

        $namespace = $config->getNamespace($environment);

        // 'web' stays up (needed to read storage/app/public live); any
        // self-hosted DB driver stays up too (needed to dump it); everything
        // else in the namespace (queues, scheduler, …) is a write source we
        // can safely pause — same --quiesce convention migrateCommons() uses.
        $databaseItem = collect($items)->firstWhere('type', 'database');
        $exclude = array_values(array_filter(['web', $databaseItem['driver']?->value ?? null]));

        $original = [];
        if ($this->flag('quiesce')) {
            $original = $this->quiesceAppDeployments($sourceKubectl, $namespace, $exclude);
        }

        $results = [];

        try {
            foreach ($items as $item) {
                $ok = match ($item['type']) {
                    'sqlite' => $this->pipeExecArchive($sourceKubectl, $destinationKubectl, $namespace, 'web', 'php', '/var/lib/larakube', 'database.sqlite'),
                    'storage' => $this->pipeExecArchive($sourceKubectl, $destinationKubectl, $namespace, 'web', 'php', '/var/www/html/'.dirname($item['path']), basename($item['path'])),
                    'database' => $this->pipeExecDump($sourceKubectl, $destinationKubectl, $namespace, $item['driver']),
                };

                $results[$item['label']] = $ok;

                $this->line($ok
                    ? "  <fg=green>✓</> Copied {$item['label']}."
                    : "  <fg=red>✗</> Could not copy {$item['label']} — copy it manually before relying on the new cluster.");
            }
        } finally {
            if ($this->flag('quiesce')) {
                $this->resumeAppDeployments($sourceKubectl, $namespace, $original);
            }
        }

        return $results;
    }

    /** Tar a directory out of the source pod, pipe it, untar into the destination pod at the same path. */
    private function pipeExecArchive(string $sourceKubectl, string $destinationKubectl, string $namespace, string $deployment, string $container, string $dir, string $base): bool
    {
        $work = TemporaryDirectory::make()->deleteWhenDestroyed();
        $archive = $work->path('archive.tar.gz');

        $dumped = Process::timeout(900)->run(
            "{$sourceKubectl} exec deploy/{$deployment} -n ".escapeshellarg($namespace)." -c {$container} -- "
            .'tar czf - -C '.escapeshellarg($dir).' '.escapeshellarg($base)
            .' > '.escapeshellarg($archive),
        )->successful();

        if (! $dumped || $this->sizeOf($archive) === 0) {
            return false;
        }

        return Process::timeout(900)->run(
            'cat '.escapeshellarg($archive)." | {$destinationKubectl} exec -i deploy/{$deployment} -n ".escapeshellarg($namespace)." -c {$container} -- "
            .'tar xzf - -C '.escapeshellarg($dir),
        )->successful();
    }

    /** Dump the source's self-hosted database, pipe it, restore it into the destination's (same driver, freshly deployed). */
    private function pipeExecDump(string $sourceKubectl, string $destinationKubectl, string $namespace, DatabaseDriver $driver): bool
    {
        // permission() must be set BEFORE create() — make() already creates
        // the directory, so setting it after would be a no-op on disk.
        $work = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $dump = $work->path('dump.sql');

        $dumped = Process::timeout(900)->run(
            "{$sourceKubectl} exec deploy/{$driver->value} -n ".escapeshellarg($namespace).' -- sh -c '
            .escapeshellarg($driver->selfHostedDumpCommand())
            .' > '.escapeshellarg($dump),
        )->successful();

        if (! $dumped || $this->sizeOf($dump) === 0) {
            return false;
        }

        return Process::timeout(900)->run(
            'cat '.escapeshellarg($dump)." | {$destinationKubectl} exec -i deploy/{$driver->value} -n ".escapeshellarg($namespace).' -- sh -c '
            .escapeshellarg($driver->selfHostedRestoreCommand()),
        )->successful();
    }
}
