<?php

namespace App\Commands\Backup;

use App\Services\Backup\DatabaseRestorer;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;

use LaravelZero\Framework\Commands\Command;

/**
 * Fetch, decrypt and inspect a backup — and restore from it.
 *
 * Verification is the default and the important mode. An untested backup is a
 * hypothesis; this proves the archive downloads, the passphrase decrypts it,
 * and the expected dumps are inside, without touching the live cluster. Only a
 * named --database/--volume, or an explicit selection at the prompt, writes
 * anything, and both go through one destructive confirmation.
 *
 * Volumes restore through a throwaway pod rather than the service's own. See
 * restoreVolume() for why the obvious recipe cannot work.
 */
class BackupRestoreCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'backup:restore
        {environment=local : Environment whose cluster to restore into}
        {--backup=    : Which backup to use, e.g. 2026-08-08-031116 (defaults to the most recent complete one)}
        {--deep       : Download and decrypt EVERY item to prove the backup is readable. This is the drill.}
        {--endpoint=  : Destination endpoint. Use when the cluster is gone.}
        {--bucket=    : Destination bucket. Use when the cluster is gone.}
        {--access-key= : Access key. Use when the cluster is gone.}
        {--region=    : Region override (defaults to us-east-1 for AWS S3, auto otherwise).}
        {--recovery-file= : Path to recovery card file (default: ~/.larakube/backup-recovery.txt).}
        {--database=  : Restore just this database, live. Destructive.}
        {--volume=    : Restore just this volume, live. Destructive — scales its service down first.}
        {--dry-run    : Verify only, and never offer to restore. Default when nothing is named.}
        {--keep       : Leave the unpacked archive on disk instead of deleting it.}
        {--force      : Skip the confirmation prompt}
        {--context=   : Target a specific kube-context}';

    protected $description = 'Verify a backup, or restore databases and volumes from it';

    public function handle(): int
    {
        $this->renderHeader();

        $kubectl = Kubectl::forContext($this->resolveToolContext(
            (string) $this->argument('environment'),
            (string) $this->option('context') ?: null,
        ))->prefix();
        $config = $this->resolveRestoreConfig($kubectl);

        if ($config === null) {
            $this->laraKubeError('No backup destination available.');
            $this->newLine();
            $this->line('  <fg=gray>Either run `larakube backup:init` on a live cluster, or — if the cluster');
            $this->line('  is gone, which is what this command is for — pass the destination directly:</>');
            $this->newLine();
            $this->line('  <fg=blue>larakube backup:restore --recovery-file=~/.larakube/backup-recovery.txt</>');
            $this->newLine();
            $this->line('  <fg=gray>Or pass --endpoint= --bucket= --access-key= with LARAKUBE_BACKUP_SECRET_KEY');
            $this->line('  and LARAKUBE_BACKUP_PASSPHRASE in the environment. Credentials are on the');
            $this->line('  recovery card `backup:init` wrote to ~/.larakube/backup-recovery.txt.</>');
            $this->newLine();

            return 1;
        }

        $stamp = (string) ($this->option('backup') ?: $this->latestCompleteRun($config));

        if ($stamp === '') {
            $this->laraKubeError('No completed backups found at the destination.');
            $this->line('  <fg=gray>A run that died mid-upload writes no manifest and is never');
            $this->line('  offered for restore. Check</> <fg=blue>larakube backup:list</><fg=gray>.</>');

            return 1;
        }

        // The manifest alone, not the data. This is the whole point of the
        // per-item layout: the picker is built from a few hundred bytes, so
        // restoring a 39KB database no longer downloads 55MB first.
        $manifest = null;
        $this->withSpin("Reading the manifest for {$stamp}...", function () use ($config, $stamp, &$manifest): void {
            $manifest = $this->fetchManifest($config, $stamp);
        });

        if ($manifest === null) {
            $this->laraKubeError("No readable manifest for '{$stamp}' — that backup did not finish.");

            return 1;
        }

        $items = $manifest['items'];
        $dbs = array_values(array_filter($items, fn (array $i) => $i['kind'] === 'database'));
        $vols = array_values(array_filter($items, fn (array $i) => $i['kind'] === 'volume'));

        $this->laraKubeNewLine();
        $this->laraKubeInfo("Backup {$stamp}");
        $this->newLine();
        $this->line('  <fg=gray>Taken:</>     <fg=blue>'.($manifest['taken_at'] ?? 'unknown').'</>');
        $this->line('  <fg=gray>Contains:</>  <fg=blue>'.count($dbs).'</> <fg=gray>databases,</> <fg=blue>'.count($vols).'</> <fg=gray>volumes</>');
        $this->newLine();

        $database = (string) ($this->option('database') ?? '');
        $volume = (string) ($this->option('volume') ?? '');

        // --deep is the drill. Per-item restore means the default path no
        // longer proves the archive is readable end to end, so verification has
        // to become something you ask for — otherwise this layout silently
        // removes the only evidence the backups work.
        if ($this->option('deep')) {
            return $this->verifyDeep($config, $stamp, $items);
        }

        // --dry-run wins over every naming flag. It reads as "prove it, safely",
        // and combined with --force it would otherwise skip the one confirmation
        // prompt and overwrite live data — the opposite of what was asked.
        if ($this->option('dry-run')) {
            if ($database !== '' || $volume !== '') {
                $this->laraKubeWarn('--dry-run given, so nothing was restored. Drop it to restore.');
            }

            $this->line('  <fg=gray>Listing only — nothing was downloaded. To prove the data really');
            $this->line('  restores, run</> <fg=blue>larakube backup:restore --deep</><fg=gray>.</>');
            $this->newLine();

            return 0;
        }

        if ($database === '' && $volume === '') {
            $picked = $this->offerRestoreTargets($dbs, $vols);

            if ($picked === []) {
                $this->line('  <fg=gray>Nothing restored.</> <fg=gray>Name a target with</> <fg=blue>--database=</> <fg=gray>or</> <fg=blue>--volume=</><fg=gray>, or verify everything with</> <fg=blue>--deep</><fg=gray>.</>');
                $this->newLine();

                return 0;
            }
        } else {
            $picked = [];
            if ($database !== '') {
                $picked[] = ['kind' => 'database', 'name' => $database];
            }
            if ($volume !== '') {
                $picked[] = ['kind' => 'volume', 'name' => $volume];
            }
        }

        $work = $this->workDir();
        $exit = $this->fetchItems($config, $stamp, $items, $picked, $work) === false
            ? 1
            : $this->restoreMany($kubectl, $work, $stamp, $picked);

        $this->cleanUp($work, $exit !== 0);

        return $exit;
    }

    /**
     * A private working directory for this run.
     *
     * date('His') alone repeats every 24h, and a second run landing in a
     * populated dir would mix two backups' files together.
     */
    protected function workDir(): string
    {
        $work = sys_get_temp_dir().'/larakube-restore-'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6);
        mkdir($work, 0700, true);

        return $work;
    }

    /**
     * Download and decrypt exactly the selected items.
     *
     * @param  array<string, string>  $config
     * @param  array<int, array{kind: string, name: string, object: string, bytes: int}>  $items
     * @param  array<int, array{kind: string, name: string}>  $picked
     */
    protected function fetchItems(array $config, string $stamp, array $items, array $picked, string $work): bool
    {
        foreach ($picked as $want) {
            $item = collect($items)->first(
                fn (array $i) => $i['kind'] === $want['kind'] && $i['name'] === $want['name'],
            );

            if ($item === null) {
                $this->laraKubeError("Backup {$stamp} contains no {$want['kind']} '{$want['name']}'.");

                return false;
            }

            if (! $this->fetchItem($config, $stamp, $item, $work)) {
                $this->laraKubeError("Could not download or decrypt {$item['kind']} {$item['name']}.");

                return false;
            }
        }

        return true;
    }

    /**
     * One object: download, decrypt, land it where the restore code expects.
     *
     * @param  array<string, string>  $config
     * @param  array{kind: string, name: string, object: string, bytes: int}  $item
     */
    protected function fetchItem(array $config, string $stamp, array $item, string $work): bool
    {
        $sealed = "{$work}/{$item['object']}";
        // Strip the .enc the uploader added: everything downstream reads
        // db-<name>.sql.gz / vol-<name>.tar.gz.
        $plain = "{$work}/".substr($item['object'], 0, -4);
        $key = $this->backupRunPrefix($stamp).$item['object'];
        $size = $this->humanBytes($item['bytes']);

        return $this->withSpin("Downloading {$item['name']} ({$size})...", fn () => Process::timeout(1800)
            ->env($this->backupAwsEnv($config))->run(
                'aws --endpoint-url '.escapeshellarg($config['endpoint'])
                .' s3 cp '.escapeshellarg("s3://{$config['bucket']}/{$key}").' '.escapeshellarg($sealed),
            )->successful() && Process::timeout(600)->env(['PASSPHRASE' => $config['passphrase']])->run(
                'openssl enc -d -aes-256-cbc -pbkdf2 -pass env:PASSPHRASE'
                .' -in '.escapeshellarg($sealed).' -out '.escapeshellarg($plain),
            )->successful());
    }

    /**
     * The drill: pull and decrypt every item, and say which ones failed.
     *
     * Reports per item rather than aborting on the first failure — during a
     * verification you want the whole picture, not the first symptom.
     *
     * @param  array<string, string>  $config
     * @param  array<int, array{kind: string, name: string, object: string, bytes: int}>  $items
     */
    protected function verifyDeep(array $config, string $stamp, array $items): int
    {
        $work = $this->workDir();
        $failed = [];

        foreach ($items as $item) {
            if (! $this->fetchItem($config, $stamp, $item, $work)) {
                $failed[] = "{$item['kind']} {$item['name']}";
            }
        }

        $this->cleanUp($work);
        $this->laraKubeNewLine();

        if ($failed !== []) {
            $this->laraKubeError('Verification FAILED for: '.implode(', ', $failed));
            $this->newLine();
            $this->line('  <fg=gray>These objects are named in the manifest but could not be downloaded');
            $this->line('  or decrypted. This backup would not fully restore.</>');
            $this->newLine();

            return 1;
        }

        $this->laraKubeInfo('✅ Every item downloads and decrypts — '.count($items).' objects.');
        $this->newLine();
        $this->line('  <fg=gray>That proves the archive is readable. It does not prove the data is');
        $this->line('  good — only an actual restore does that.</>');
        $this->newLine();

        return 0;
    }

    /**
     * Ask which items to restore.
     *
     * Nothing is pre-selected on purpose: this prompt appears on the plain
     * verification path, so Enter has to be the answer that changes nothing.
     *
     * @param  array<int, array{kind: string, name: string, object: string, bytes: int}>  $dbs
     * @param  array<int, array{kind: string, name: string, object: string, bytes: int}>  $vols
     * @return array<int, array{kind: string, name: string}>
     */
    protected function offerRestoreTargets(array $dbs, array $vols): array
    {
        if ($this->cannotPrompt()) {
            $this->line('  <fg=gray>Restore one database:</> <fg=blue>larakube backup:restore --database=<database></>');
            $this->line('  <fg=gray>Restore one volume:</>   <fg=blue>larakube backup:restore --volume=forgejo</>');
            $this->newLine();

            return [];
        }

        $byLargest = fn (array $a, array $b) => $b['bytes'] <=> $a['bytes'];
        usort($dbs, $byLargest);
        usort($vols, $byLargest);

        $options = [];
        foreach ($dbs as $item) {
            $options["database:{$item['name']}"] = sprintf('database  %-22s %s', $item['name'], $this->humanBytes($item['bytes']));
        }
        foreach ($vols as $item) {
            $options["volume:{$item['name']}"] = sprintf('volume    %-22s %s', $item['name'], $this->humanBytes($item['bytes']));
        }

        if ($options === []) {
            return [];
        }

        $selected = multiselect(
            label: 'Restore anything from this backup?',
            options: $options,
            default: [],
            // The list IS the inventory now, so it must not scroll — a hidden
            // row is the one you needed. Prompts defaults to 5.
            scroll: max(10, count($options)),
            hint: 'Nothing is selected — press Enter to exit without downloading. Only what you pick is fetched. Volumes scale their service down first.',
        );

        return collect($selected)->map(function (string $key) {
            [$kind, $name] = explode(':', $key, 2);

            return ['kind' => $kind, 'name' => $name];
        })->all();
    }

    /**
     * Restore each selected item, confirming once for the whole set.
     *
     * @param  array<int, array{kind: string, name: string}>  $picked
     */
    protected function restoreMany(string $kubectl, string $work, string $object, array $picked): int
    {
        $lines = collect($picked)->map(fn (array $i) => "  {$i['kind']} {$i['name']}")->all();

        if (! $this->confirmDestructive(array_merge(
            ["Overwrite the following with the copy from {$object}:"],
            $lines,
            ['Every change made since that backup will be lost.'],
        ))) {
            return 1;
        }

        $failed = [];

        foreach ($picked as $item) {
            $ok = $item['kind'] === 'database'
                ? $this->restoreDatabase($kubectl, $work, $item['name'])
                : $this->restoreVolume($kubectl, $work, $item['name']);

            if (! $ok) {
                $failed[] = "{$item['kind']} {$item['name']}";
            }
        }

        $this->laraKubeNewLine();

        if ($failed !== []) {
            $this->laraKubeError('Restore incomplete. Failed: '.implode(', ', $failed));
            $this->line("  <fg=gray>The unpacked archive is kept at {$work} so you can finish by hand.</>");
            $this->newLine();

            return 1;
        }

        $this->laraKubeInfo('✅ Restored: '.collect($picked)->pluck('name')->implode(', '));
        $this->newLine();
        $this->line('  <fg=gray>Restart whatever uses this data so it reconnects.</>');
        $this->newLine();

        return 0;
    }

    protected function restoreDatabase(string $kubectl, string $work, string $database): bool
    {
        $dump = "{$work}/db-{$database}.sql.gz";

        if (! file_exists($dump)) {
            $this->laraKubeError("This backup contains no dump for '{$database}'.");

            return false;
        }

        // The dump was written by whatever engine Commons actually runs, so the
        // load has to be read off the same enum rather than assumed to be psql.
        $driver = $this->commonsDatabaseDriver($kubectl);

        if ($driver === null || $driver->commonsAdminRestoreCommand($database) === '') {
            $this->laraKubeError('Could not determine a restore command for the Commons database engine.');
            $this->line("  <fg=gray>The unpacked dump is at {$dump} — load it by hand.</>");

            return false;
        }

        $service = $driver->commonsServiceName();
        $restorer = DatabaseRestorer::forPod($kubectl, 'larakube-plex', "deploy/{$service}", $service);

        $ok = $this->withSpin(
            "Restoring database {$database} into {$driver->value}...",
            fn () => $restorer->restore($driver, $database, $dump),
        );

        if (! $ok && $restorer->lastError() !== null) {
            $this->laraKubeError($restorer->lastError());
        }

        return $ok;
    }

    /**
     * Restore one volume through a throwaway pod.
     *
     * The obvious recipe — scale to 0, then `kubectl exec` into the deployment —
     * cannot work: scaling to 0 deletes the only pod there is to exec into. And
     * untarring into a live service risks a half-written volume under a running
     * process, which is worse than the failure being restored from.
     */
    protected function restoreVolume(string $kubectl, string $work, string $name): bool
    {
        $archive = "{$work}/vol-{$name}.tar.gz";

        if (! file_exists($archive)) {
            $this->laraKubeError("This backup contains no archive for volume '{$name}'.");

            return false;
        }

        $target = collect($this->backupVolumeTargets($kubectl))->firstWhere('name', $name);

        if ($target === null) {
            $this->laraKubeError("'{$name}' is not a known volume target on this cluster.");

            return false;
        }

        $claim = $this->resolveVolumeClaim($kubectl, $target);

        if ($claim === null) {
            $this->laraKubeError("Could not find the PVC behind volume '{$name}'.");

            return false;
        }

        $ns = $target['namespace'];
        $deploy = $target['deployment'];
        $kind = (string) ($target['kind'] ?? 'deploy');
        $pod = "larakube-restore-{$name}";
        $replicas = $this->currentReplicas($kubectl, $ns, $deploy, $kind);

        return $this->withSpin("Restoring volume {$name}...", fn () => $this->restorePvcArchive(
            $kubectl,
            $ns,
            $deploy,
            $archive,
            [
                'kind' => $kind,
                'mode' => 'live-mounts',
                'podName' => $pod,
                'replicas' => $replicas,
                'claim' => $claim['claim'],
                'mountPath' => $claim['mountPath'],
                'subPath' => (string) ($claim['subPath'] ?? ''),
                'extractDir' => dirname($target['paths'][0]),
            ],
        ));
    }

    /** Replica count to restore afterwards; preserves paused state (0), defaults to 1 if unreadable. */
    protected function currentReplicas(string $kubectl, string $ns, string $deploy, string $kind = 'deploy'): int
    {
        $raw = trim(Process::timeout(60)->run(
            "{$kubectl} get {$kind} {$deploy} -n {$ns} -o jsonpath=".escapeshellarg('{.spec.replicas}'),
        )->output());

        if ($raw !== '') {
            return max(0, (int) $raw);
        }

        $paused = trim(Process::timeout(60)->run(
            "{$kubectl} get {$kind} {$deploy} -n {$ns} -o jsonpath=".escapeshellarg('{.metadata.annotations.larakube\.app/paused-replicas}'),
        )->output());

        return $paused !== '' ? 0 : 1;
    }

    /**
     * Remove the unpacked archive.
     *
     * These are ~110MB each and nothing used to delete them, so a few restore
     * drills quietly cost a gigabyte of temp space. Kept on failure, and under
     * --keep, because that is when the files are still worth something.
     */
    protected function cleanUp(string $work, bool $keep = false): void
    {
        if ($keep || $this->option('keep')) {
            $this->line("  <fg=gray>Unpacked archive kept at</> <fg=blue>{$work}</>");
            $this->newLine();

            return;
        }

        Process::timeout(60)->run('rm -rf '.escapeshellarg($work));
    }

    /**
     * Destination for this restore.
     *
     * Flags win over the cluster, because the disaster this command exists for
     * is the cluster being gone — reading the destination *from* the thing you
     * are restoring only works for the mild failures.
     *
     * @return array{endpoint: string, bucket: string, access_key: string, secret_key: string, passphrase: string, region: string}|null
     */
    protected function resolveRestoreConfig(string $kubectl): ?array
    {
        $recoveryFile = (string) ($this->option('recovery-file') ?? '');
        $card = $recoveryFile !== '' ? $this->readRecoveryCard($recoveryFile) : null;

        $endpoint = (string) ($this->option('endpoint') ?: ($card['endpoint'] ?? ''));
        $bucket = (string) ($this->option('bucket') ?: ($card['bucket'] ?? ''));
        $regionFlag = (string) ($this->option('region') ?? '');

        if ($endpoint !== '' && $bucket !== '') {
            $accessKey = (string) ($this->option('access-key') ?: getenv('LARAKUBE_BACKUP_ACCESS_KEY') ?: ($card['access_key'] ?? ''));
            $secretKey = (string) (getenv('LARAKUBE_BACKUP_SECRET_KEY') ?: ($card['secret_key'] ?? '') ?: ($this->cannotPrompt() ? '' : password(label: 'Secret access key')));
            $passphrase = (string) (getenv('LARAKUBE_BACKUP_PASSPHRASE') ?: ($card['passphrase'] ?? '') ?: ($this->cannotPrompt() ? '' : password(label: 'Decryption passphrase')));
            $region = (string) ($regionFlag ?: getenv('LARAKUBE_BACKUP_REGION') ?: ($card['region'] ?? '') ?: $this->defaultBackupRegion($endpoint));

            return [
                'endpoint' => $endpoint,
                'bucket' => $bucket,
                'access_key' => $accessKey,
                'secret_key' => $secretKey,
                'passphrase' => $passphrase,
                'region' => $region,
            ];
        }

        $config = $this->readBackupConfig($kubectl, $this->backupNamespace());

        if ($config !== null && $regionFlag !== '') {
            $config['region'] = $regionFlag;
        }

        return $config;
    }
}
