<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
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
 * Deliberately NOT fully automatic:
 *   - DNS cutover is guidance only (inherited from cloud:deploy's own
 *     printIngressDnsGuidance() — no DNS-provider automation exists for this).
 *   - Commons volume restore stays "deliberately half-manual" per ADR 0010 —
 *     this command surfaces backup:restore's own printed instructions, never
 *     runs them for you.
 *   - The project's OWN storage (its laravel-storage/-data PVCs) and any
 *     SELF-HOSTED (non-Commons, non-externally-managed) database are not
 *     copied by anything today — see detectUnmigratedOwnStorage(). Rather
 *     than ship an untested, structurally-mismatched copy (the app's own
 *     storage PVC is mounted via several `subPath` binds, not one clean
 *     mount point the existing tar-based backup targets assume), this
 *     prints exactly what's uncovered so nothing is silently lost.
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
        {--quiesce : Scale the app to 0 replicas immediately before the final Commons snapshot, shrinking the write-loss window}
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
        $unmigrated = $this->detectUnmigratedOwnStorage($config, $environment);

        if (! $this->confirmDestructive([
            "'{$environment}' moves from '{$sourceContext}' to '{$destinationContext}':",
            $plexServices !== [] ? 'Commons structure + data will be copied to the new cluster.' : 'This environment has no Commons to migrate.',
            $unmigrated === [] ? 'Nothing else needs a manual data copy.' : (count($unmigrated).' item(s) need a MANUAL data copy — listed below, not automated.'),
            'The source cluster is left running — tear it down yourself once you have verified the new one.',
        ])) {
            return 0;
        }

        if ($unmigrated !== []) {
            $this->newLine();
            $this->laraKubeWarn('Nothing automates these — copy them yourself before (or after) this run:');
            foreach ($unmigrated as $line) {
                $this->line("  <fg=yellow>•</> {$line}");
            }
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
                'manualCopyNeeded' => $unmigrated,
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
     * The project's own storage (its laravel-storage/-data PVCs) and any
     * self-hosted (non-Commons, non-externally-managed) database — nothing
     * backs these up or copies them anywhere today (confirmed:
     * InteractsWithBackup::larakubeNamespaces() only ever scans `larakube-*`
     * namespaces, never a project's own `{name}-{env}`). Reported so it is
     * never silently lost, not attempted here — see this class's own
     * docblock for why.
     *
     * @return list<string>
     */
    private function detectUnmigratedOwnStorage(ConfigData $config, string $environment): array
    {
        $plex = $config->getPlex($environment);
        $managed = $config->getManaged($environment);
        $warnings = [];

        if ($config->hasDatabase(DatabaseDriver::SQLITE)
            && ! in_array(DatabaseDriver::SQLITE->value, $plex, true)
            && ! in_array(DatabaseDriver::SQLITE->value, $managed, true)
        ) {
            $warnings[] = "SQLite data ({$config->getName()}-laravel-data-pvc) — dump the file yourself (e.g. `larakube shell web` then copy /var/lib/larakube/database.sqlite) and place it on the new cluster before redeploying.";
        }

        foreach ($config->getDatabases() as $driver) {
            if ($driver === DatabaseDriver::SQLITE) {
                continue; // already covered above, with its own specific instructions.
            }

            if (in_array($driver->value, $plex, true) || in_array($driver->value, $managed, true)) {
                continue; // Commons-backed or externally managed — not this project's own storage.
            }

            $warnings[] = "Self-hosted {$driver->value} database — dump it yourself (e.g. `larakube shell {$driver->value}`) and restore it on the new cluster before redeploying.";
        }

        // Always true unless every byte lives in Commons/S3 — logs/cache/sessions
        // are rebuildable, but anything written under storage/app/public is not.
        if ($warnings === [] && ! in_array('storage', $managed, true)) {
            $warnings[] = "The app's own storage volume ({$config->getName()}-laravel-storage-pvc, e.g. storage/app/public) — copy anything irreplaceable in it yourself; nothing backs it up automatically.";
        }

        return $warnings;
    }
}
