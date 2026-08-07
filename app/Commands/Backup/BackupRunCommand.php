<?php

namespace App\Commands\Backup;

use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Take a full backup and ship it off the cluster.
 *
 * Runs from the operator's machine rather than as an in-cluster CronJob, on
 * purpose: a Pod would have to mount six ReadWriteOnce volumes belonging to six
 * different tools, which only works while everything is on one node and couples
 * the backup to every tool's storage layout. Streaming out through `kubectl
 * exec` needs no mounts, no privileged pod, and no image carrying pg_dump, tar
 * and an S3 client at once.
 *
 * Everything is encrypted before it leaves, because the destination is somebody
 * else's disk.
 */
class BackupRunCommand extends Command
{
    use InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'backup:run
        {environment=local : Environment whose cluster to back up}
        {--keep-local=     : Also leave the unencrypted archive in this directory}
        {--context=        : Target a specific kube-context}';

    protected $description = 'Back up every database and irreplaceable volume, encrypted, off-site';

    public function handle(): int
    {
        $this->renderHeader();

        $context = (string) $this->option('context') ?: null;
        $kubectl = $this->backupKubectl($context);
        $ns = $this->backupNamespace();

        $config = $this->readBackupConfig($kubectl, $ns);

        if ($config === null) {
            $this->laraKubeError('No backup destination configured. Run `larakube backup:init` first.');

            return 1;
        }

        $stamp = date('Y-m-d-His');
        // Suffixed: the stamp is second-granular, so two runs starting in the
        // same second would otherwise share a working directory and interleave
        // their dumps into one corrupt archive.
        $work = sys_get_temp_dir()."/larakube-backup-{$stamp}-".bin2hex(random_bytes(3));

        if (! @mkdir($work, 0700, true) && ! is_dir($work)) {
            $this->laraKubeError("Could not create a working directory at {$work}.");

            return 1;
        }

        $failures = [];

        // 1. Databases. Dumped individually so a single tool can be restored
        //    without rolling back every other tool to the same moment.
        $databases = $this->backupDatabases($kubectl);

        if ($databases === []) {
            $this->laraKubeError('Found no databases to back up — is the Commons Postgres reachable?');

            return 1;
        }

        foreach ($databases as $db) {
            $this->withSpin("Dumping database {$db}...", function () use ($kubectl, $db, $work, &$failures) {
                $result = Process::timeout(600)->run(
                    "{$kubectl} exec deploy/postgres -n larakube-plex -c postgres -- "
                    ."pg_dump -U postgres --no-owner {$db} | gzip > ".escapeshellarg("{$work}/db-{$db}.sql.gz"),
                );

                if (! $result->successful() || $this->sizeOf("{$work}/db-{$db}.sql.gz") < 100) {
                    $failures[] = "database {$db}";
                }
            });
        }

        // 2. Volumes whose contents cannot be rebuilt from anything else.
        foreach ($this->backupVolumeTargets() as $target) {
            $this->withSpin("Archiving {$target['name']}...", function () use ($kubectl, $target, $work, &$failures) {
                $dir = dirname($target['path']);
                $base = basename($target['path']);

                $result = Process::timeout(900)->run(
                    "{$kubectl} exec deploy/{$target['deployment']} -n {$target['namespace']} -c {$target['container']} -- "
                    .'tar czf - -C '.escapeshellarg($dir).' '.escapeshellarg($base)
                    .' > '.escapeshellarg("{$work}/vol-{$target['name']}.tar.gz"),
                );

                if (! $result->successful() || $this->sizeOf("{$work}/vol-{$target['name']}.tar.gz") < 50) {
                    $failures[] = "volume {$target['name']}";
                }
            });
        }

        if ($failures !== []) {
            // A partial backup that reports success is worse than no backup —
            // it is the one you find out about during the restore.
            $this->laraKubeError('Backup incomplete, nothing uploaded. Failed: '.implode(', ', $failures));
            $this->line("  <fg=gray>Working files left at {$work} for inspection.</>");

            return 1;
        }

        // 3. One encrypted archive. AES-256 via a passphrase, because the
        //    destination is a third party's disk.
        $archive = sys_get_temp_dir()."/larakube-{$stamp}.tar.gz.enc";

        $sealed = $this->withSpin('Encrypting...', fn () => Process::timeout(600)->run(
            'tar czf - -C '.escapeshellarg($work).' . | '
            .'openssl enc -aes-256-cbc -pbkdf2 -salt -pass '.escapeshellarg("pass:{$config['passphrase']}")
            .' -out '.escapeshellarg($archive),
        )->successful());

        if (! $sealed || ! file_exists($archive)) {
            $this->laraKubeError('Encryption failed — nothing uploaded.');

            return 1;
        }

        $size = $this->humanBytes($this->sizeOf($archive));
        $key = "larakube/{$stamp}.tar.gz.enc";

        $uploaded = $this->withSpin("Uploading {$size} off-site...", fn () => Process::timeout(1800)->env([
            'AWS_ACCESS_KEY_ID' => $config['access_key'],
            'AWS_SECRET_ACCESS_KEY' => $config['secret_key'],
            'AWS_DEFAULT_REGION' => $config['region'],
        ])->run(
            'aws --endpoint-url '.escapeshellarg($config['endpoint'])
            .' s3 cp '.escapeshellarg($archive).' '.escapeshellarg("s3://{$config['bucket']}/{$key}"),
        )->successful());

        if ($this->option('keep-local')) {
            $dest = rtrim((string) $this->option('keep-local'), '/');
            Process::run('cp '.escapeshellarg($archive).' '.escapeshellarg("{$dest}/"));
        }

        Process::run('rm -rf '.escapeshellarg($work).' '.escapeshellarg($archive));

        if (! $uploaded) {
            $this->laraKubeError('Upload failed — the backup was NOT stored off-site.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Backup complete and off-site.');
        $this->newLine();
        $this->line('  <fg=gray>Databases:</> <fg=blue>'.count($databases).'</>');
        $this->line('  <fg=gray>Volumes:</>   <fg=blue>'.count($this->backupVolumeTargets()).'</>');
        $this->line("  <fg=gray>Size:</>      <fg=blue>{$size}</>");
        $this->line("  <fg=gray>Stored:</>    <fg=blue>s3://{$config['bucket']}/{$key}</>");
        $this->newLine();
        $this->line('  <fg=gray>A backup you have never restored is a hypothesis. Try one:</>');
        $this->line('  <fg=blue>larakube backup:restore --dry-run</>');
        $this->newLine();

        return 0;
    }

    /**
     * Size of a produced artifact, 0 when absent.
     *
     * A command that exits 0 having written nothing is a real failure mode —
     * `kubectl exec` can succeed while the pod's tar writes to a path that does
     * not exist — so a missing file must read as "empty", not raise.
     */
    protected function sizeOf(string $path): int
    {
        return file_exists($path) ? (int) filesize($path) : 0;
    }

    protected function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).$unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).'TB';
    }
}
