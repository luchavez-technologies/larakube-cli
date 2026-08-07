<?php

namespace App\Commands\Backup;

use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Fetch, decrypt and inspect a backup — and optionally restore a database.
 *
 * `--dry-run` is the default and the important mode. An untested backup is a
 * hypothesis; this proves the archive downloads, the passphrase decrypts it,
 * and the expected dumps are inside, without touching the live cluster.
 *
 * Restoring volumes is deliberately NOT automated. Every one of them belongs to
 * a running service that would need stopping first, and a half-restored volume
 * under a live process is worse than the failure being restored from. The
 * archive is left unpacked with the exact commands printed instead.
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
        {--dry-run    : Download, decrypt and verify only. Default when --database is absent.}
        {--force      : Skip the confirmation prompt}
        {--context=   : Target a specific kube-context}';

    protected $description = 'Verify a backup, or restore a single database from it';

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

        $work = sys_get_temp_dir().'/larakube-restore-'.date('His');
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

        $dbs = glob("{$work}/db-*.sql.gz") ?: [];
        $vols = glob("{$work}/vol-*.tar.gz") ?: [];

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Backup verified — it downloads, decrypts and unpacks.');
        $this->newLine();
        $this->line('  <fg=gray>Databases:</> <fg=blue>'.count($dbs).'</>');
        $this->line('  <fg=gray>Volumes:</>   <fg=blue>'.count($vols).'</>');
        $this->line("  <fg=gray>Unpacked:</>  <fg=blue>{$work}</>");
        $this->newLine();

        $database = (string) ($this->option('database') ?? '');

        // --dry-run has to win over --database. It reads as "prove it, safely",
        // and combined with --force it would otherwise skip the one confirmation
        // prompt and overwrite a live database — the opposite of what was asked.
        if ($database !== '' && $this->option('dry-run')) {
            $this->laraKubeWarn("--dry-run given, so '{$database}' was NOT restored. Drop --dry-run to restore it.");
            $this->newLine();

            return 0;
        }

        if ($database === '') {
            $this->line('  <fg=gray>Restore one database:</> <fg=blue>larakube backup:restore --database=chat_matrix</>');
            $this->newLine();
            $this->line('  <fg=gray>Volumes are restored by hand, on purpose — each belongs to a running');
            $this->line('  service that must be stopped first. For example:</>');
            $this->newLine();
            $this->line('  <fg=gray>  kubectl scale deploy/forgejo -n larakube-shared --replicas=0</>');
            $this->line("  <fg=gray>  kubectl exec -i deploy/forgejo -n larakube-shared -- tar xzf - -C / < {$work}/vol-forgejo.tar.gz</>");
            $this->line('  <fg=gray>  kubectl scale deploy/forgejo -n larakube-shared --replicas=1</>');
            $this->newLine();

            return 0;
        }

        $dump = "{$work}/db-{$database}.sql.gz";

        if (! file_exists($dump)) {
            $this->laraKubeError("This backup contains no dump for '{$database}'.");

            return 1;
        }

        // The dump was written by whatever engine Commons actually runs, so the
        // load has to be read off the same enum rather than assumed to be psql.
        $driver = $this->commonsDatabaseDriver($kubectl);

        if ($driver === null || $driver->commonsAdminRestoreCommand($database) === '') {
            $this->laraKubeError('Could not determine a restore command for the Commons database engine.');
            $this->line("  <fg=gray>The unpacked dump is at {$dump} — load it by hand.</>");

            return 1;
        }

        $service = $driver->commonsServiceName();

        if (! $this->confirmDestructive([
            "Overwrite the live '{$database}' database with the copy from {$object}.",
            'Every change made since that backup will be lost.',
        ])) {
            return 1;
        }

        $restored = $this->withSpin("Restoring {$database} into {$driver->value}...", fn () => Process::timeout(1800)->run(
            'gunzip -c '.escapeshellarg($dump)." | {$kubectl} exec -i deploy/{$service} -n larakube-plex -c {$service} -- "
            .'sh -c '.escapeshellarg($driver->commonsAdminRestoreCommand($database)),
        )->successful());

        if (! $restored) {
            $this->laraKubeError("Restore of {$database} failed. The unpacked dump is at {$dump}.");

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ {$database} restored.");
        $this->newLine();
        $this->line('  <fg=gray>Restart whatever uses it so it reconnects to the restored data.</>');
        $this->newLine();

        return 0;
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
