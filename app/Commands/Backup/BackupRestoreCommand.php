<?php

namespace App\Commands\Backup;

use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\table;

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
        {--object=    : Which backup to use (defaults to the most recent)}
        {--endpoint=  : Destination endpoint. Use when the cluster is gone.}
        {--bucket=    : Destination bucket. Use when the cluster is gone.}
        {--access-key= : Access key. Use when the cluster is gone.}
        {--secret-key= : Secret key. Use when the cluster is gone.}
        {--passphrase= : Decryption passphrase. Use when the cluster is gone.}
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

        $kubectl = $this->backupKubectl($this->resolveToolContext(
            (string) $this->argument('environment'),
            (string) $this->option('context') ?: null,
        ));
        $config = $this->resolveRestoreConfig($kubectl);

        if ($config === null) {
            $this->laraKubeError('No backup destination available.');
            $this->newLine();
            $this->line('  <fg=gray>Either run `larakube backup:init` on a live cluster, or — if the cluster');
            $this->line('  is gone, which is what this command is for — pass the destination directly:</>');
            $this->newLine();
            $this->line('  <fg=blue>larakube backup:restore --endpoint=… --bucket=… \\</>');
            $this->line('  <fg=blue>    --access-key=… --secret-key=… --passphrase=…</>');
            $this->newLine();
            $this->line('  <fg=gray>Those five values are in the recovery card `backup:init` wrote to');
            $this->line('  ~/.larakube/backup-recovery.txt, and hopefully somewhere else too.</>');
            $this->newLine();

            return 1;
        }

        $object = (string) ($this->option('object') ?: $this->latestObject($config));

        if ($object === '') {
            $this->laraKubeError('No backups found at the destination.');

            return 1;
        }

        // date('His') alone repeats every 24h, and a second run landing in a
        // populated dir would inflate the verification counts with the previous
        // archive's files.
        $work = sys_get_temp_dir().'/larakube-restore-'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6);
        mkdir($work, 0700, true);
        $archive = "{$work}/backup.enc";

        $got = $this->withSpin("Downloading {$object}...", fn () => Process::timeout(1800)->env($this->backupAwsEnv($config))->run(
            'aws --endpoint-url '.escapeshellarg($config['endpoint'])
            .' s3 cp '.escapeshellarg("s3://{$config['bucket']}/{$object}").' '.escapeshellarg($archive),
        )->successful());

        if (! $got) {
            $this->laraKubeError('Download failed.');

            return 1;
        }

        $opened = $this->withSpin('Decrypting...', fn () => Process::timeout(600)->run(
            'openssl enc -d -aes-256-cbc -pbkdf2 -pass '.escapeshellarg("pass:{$config['passphrase']}")
            .' -in '.escapeshellarg($archive).' | tar xzf - -C '.escapeshellarg($work),
        )->successful());

        if (! $opened) {
            $this->laraKubeError('Decryption failed — wrong passphrase, or the archive is damaged.');
            $this->line('  <fg=gray>If this cluster was rebuilt, the passphrase in its Secret is a NEW one.');
            $this->line('  You need the passphrase printed when backup:init first ran.</>');

            return 1;
        }

        $dbs = $this->inventory($work, 'db-', '.sql.gz');
        $vols = $this->inventory($work, 'vol-', '.tar.gz');

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Backup verified — it downloads, decrypts and unpacks.');
        $this->newLine();
        $this->line("  <fg=gray>Unpacked:</>  <fg=blue>{$work}</>");
        $this->newLine();

        // Sizes, not just counts: a 26MB database and a 4KB volume are both one
        // line in a list, and knowing which is which is the difference between
        // picking confidently and picking blind during an incident.
        table(
            ['', 'Name', 'Size'],
            collect($dbs)->map(fn (array $i) => ['database', $i['name'], $this->humanBytes($i['size'])])
                ->merge(collect($vols)->map(fn (array $i) => ['volume', $i['name'], $this->humanBytes($i['size'])]))
                ->all(),
        );
        $this->newLine();

        $database = (string) ($this->option('database') ?? '');
        $volume = (string) ($this->option('volume') ?? '');

        // --dry-run wins over every naming flag. It reads as "prove it, safely",
        // and combined with --force it would otherwise skip the one confirmation
        // prompt and overwrite live data — the opposite of what was asked.
        if ($this->option('dry-run')) {
            if ($database !== '' || $volume !== '') {
                $this->laraKubeWarn('--dry-run given, so nothing was restored. Drop it to restore.');
                $this->newLine();
            }

            $this->cleanUp($work);

            return 0;
        }

        // Nothing named: offer what is in the archive rather than making the
        // operator re-run — a second run re-downloads and re-decrypts the whole
        // archive, which is a slow thing to repeat mid-incident.
        if ($database === '' && $volume === '') {
            $picked = $this->offerRestoreTargets($dbs, $vols);

            if ($picked === []) {
                $this->line('  <fg=gray>Nothing restored.</> <fg=gray>Name a target directly with</> <fg=blue>--database=</> <fg=gray>or</> <fg=blue>--volume=</><fg=gray>.</>');
                $this->newLine();
                $this->cleanUp($work);

                return 0;
            }

            $exit = $this->restoreMany($kubectl, $work, $object, $picked);
            $this->cleanUp($work, $exit !== 0);

            return $exit;
        }

        $picked = [];
        if ($database !== '') {
            $picked[] = ['kind' => 'database', 'name' => $database];
        }
        if ($volume !== '') {
            $picked[] = ['kind' => 'volume', 'name' => $volume];
        }

        $exit = $this->restoreMany($kubectl, $work, $object, $picked);
        $this->cleanUp($work, $exit !== 0);

        return $exit;
    }

    /**
     * What the archive contains, with sizes.
     *
     * @return array<int, array{name: string, path: string, size: int}>
     */
    protected function inventory(string $work, string $prefix, string $suffix): array
    {
        $items = [];

        foreach (glob("{$work}/{$prefix}*{$suffix}") ?: [] as $path) {
            $items[] = [
                'name' => substr(basename($path), strlen($prefix), -strlen($suffix)),
                'path' => $path,
                'size' => $this->sizeOf($path),
            ];
        }

        usort($items, fn (array $a, array $b) => $b['size'] <=> $a['size']);

        return $items;
    }

    /**
     * Ask which items to restore.
     *
     * Nothing is pre-selected on purpose: this prompt appears on the plain
     * verification path, so Enter has to be the answer that changes nothing.
     *
     * @param  array<int, array{name: string, path: string, size: int}>  $dbs
     * @param  array<int, array{name: string, path: string, size: int}>  $vols
     * @return array<int, array{kind: string, name: string}>
     */
    protected function offerRestoreTargets(array $dbs, array $vols): array
    {
        if ($this->cannotPrompt()) {
            $this->line('  <fg=gray>Restore one database:</> <fg=blue>larakube backup:restore --database=chat_matrix</>');
            $this->line('  <fg=gray>Restore one volume:</>   <fg=blue>larakube backup:restore --volume=forgejo</>');
            $this->newLine();

            return [];
        }

        $options = [];
        foreach ($dbs as $item) {
            $options["database:{$item['name']}"] = "database  {$item['name']}  ({$this->humanBytes($item['size'])})";
        }
        foreach ($vols as $item) {
            $options["volume:{$item['name']}"] = "volume    {$item['name']}  ({$this->humanBytes($item['size'])})";
        }

        if ($options === []) {
            return [];
        }

        $selected = multiselect(
            label: 'Restore anything from this backup?',
            options: $options,
            default: [],
            hint: 'Nothing is selected — press Enter to just verify and exit. Volumes scale their service down first.',
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

        return $this->withSpin("Restoring database {$database} into {$driver->value}...", fn () => Process::timeout(1800)->run(
            'gunzip -c '.escapeshellarg($dump)." | {$kubectl} exec -i deploy/{$service} -n larakube-plex -c {$service} -- "
            .'sh -c '.escapeshellarg($driver->commonsAdminRestoreCommand($database)),
        )->successful());
    }

    /**
     * Restore one volume through a throwaway pod.
     *
     * The obvious recipe — scale to 0, then `kubectl exec` into the deployment —
     * cannot work: scaling to 0 deletes the only pod there is to exec into. And
     * untarring into a live service risks a half-written volume under a running
     * process, which is worse than the failure being restored from.
     *
     * So: stop the service, mount its claim into a plain alpine pod at the SAME
     * path the real pod uses (the archive's leading component is the mount point
     * itself), write, then put everything back — including on failure.
     */
    protected function restoreVolume(string $kubectl, string $work, string $name): bool
    {
        $archive = "{$work}/vol-{$name}.tar.gz";

        if (! file_exists($archive)) {
            $this->laraKubeError("This backup contains no archive for volume '{$name}'.");

            return false;
        }

        $target = collect($this->backupVolumeTargets())->firstWhere('name', $name);

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
        $pod = "larakube-restore-{$name}";
        $replicas = $this->currentReplicas($kubectl, $ns, $deploy);

        $ok = $this->withSpin("Stopping {$deploy}...", fn () => Process::timeout(300)->run(
            "{$kubectl} scale deploy/{$deploy} -n {$ns} --replicas=0",
        )->successful() && Process::timeout(300)->run(
            // Without this the claim can still be attached when the helper pod
            // tries to mount it, and ReadWriteOnce means the helper never starts.
            "{$kubectl} wait --for=delete pod -l app={$deploy} -n {$ns} --timeout=180s",
        )->successful());

        try {
            if (! $ok) {
                $this->laraKubeError("Could not stop {$deploy} — refusing to write to a volume still in use.");

                return false;
            }

            $overrides = json_encode([
                'spec' => [
                    'containers' => [[
                        'name' => 'restore',
                        'image' => 'alpine:3.20',
                        'command' => ['sleep', '600'],
                        'volumeMounts' => [['name' => 'data', 'mountPath' => $claim['mountPath']]],
                    ]],
                    'volumes' => [['name' => 'data', 'persistentVolumeClaim' => ['claimName' => $claim['claim']]]],
                ],
            ], JSON_THROW_ON_ERROR);

            $started = $this->withSpin("Mounting {$claim['claim']}...", fn () => Process::timeout(300)->run(
                "{$kubectl} run {$pod} -n {$ns} --image=alpine:3.20 --restart=Never "
                .'--overrides='.escapeshellarg($overrides),
            )->successful() && Process::timeout(300)->run(
                "{$kubectl} wait --for=condition=Ready pod/{$pod} -n {$ns} --timeout=180s",
            )->successful());

            if (! $started) {
                $this->laraKubeError("Helper pod for '{$name}' never became ready.");

                return false;
            }

            // -C dirname(path) mirrors how backup:run created it: `tar -C
            // dirname basename`, so the archive's first component IS the mount
            // point. Extracting anywhere else nests it one level too deep.
            return $this->withSpin("Restoring volume {$name}...", fn () => Process::timeout(1800)->run(
                "{$kubectl} exec -i {$pod} -n {$ns} -- tar xzf - -C ".escapeshellarg(dirname($target['path']))
                .' < '.escapeshellarg($archive),
            )->successful());
        } finally {
            // Always, even when the untar failed: a service left at 0 replicas
            // because a restore errored is an outage this command caused.
            Process::timeout(300)->run("{$kubectl} delete pod {$pod} -n {$ns} --ignore-not-found");
            $this->withSpin("Starting {$deploy}...", fn () => Process::timeout(300)->run(
                "{$kubectl} scale deploy/{$deploy} -n {$ns} --replicas={$replicas}",
            ));
        }
    }

    /** Replica count to restore afterwards; 1 when it cannot be read. */
    protected function currentReplicas(string $kubectl, string $ns, string $deploy): int
    {
        $raw = trim(Process::timeout(60)->run(
            "{$kubectl} get deploy {$deploy} -n {$ns} -o jsonpath=".escapeshellarg('{.spec.replicas}'),
        )->output());

        return $raw === '' ? 1 : max(1, (int) $raw);
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
        $endpoint = (string) ($this->option('endpoint') ?? '');
        $bucket = (string) ($this->option('bucket') ?? '');

        if ($endpoint !== '' && $bucket !== '') {
            return [
                'endpoint' => $endpoint,
                'bucket' => $bucket,
                'access_key' => (string) ($this->option('access-key') ?? ''),
                'secret_key' => (string) ($this->option('secret-key') ?? ''),
                'passphrase' => (string) ($this->option('passphrase') ?? ''),
                'region' => 'auto',
            ];
        }

        return $this->readBackupConfig($kubectl, $this->backupNamespace());
    }

    /** @param array<string, string> $config */
    protected function latestObject(array $config): string
    {
        $out = Process::timeout(120)->env($this->backupAwsEnv($config))->run(
            'aws --endpoint-url '.escapeshellarg($config['endpoint'])
            .' s3 ls '.escapeshellarg("s3://{$config['bucket']}/larakube/"),
        )->output();

        $keys = [];
        foreach (array_filter(array_map('trim', explode("\n", $out))) as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 4) {
                $keys[] = 'larakube/'.$parts[3];
            }
        }

        sort($keys);

        return $keys === [] ? '' : (string) end($keys);
    }
}
