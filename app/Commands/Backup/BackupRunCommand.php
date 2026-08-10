<?php

namespace App\Commands\Backup;

use App\Traits\DeploysClusterTool;
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
    use DeploysClusterTool, InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'backup:run
        {environment=local : Environment whose cluster to back up}
        {--keep-local=     : Also leave the unencrypted archive in this directory}
        {--context=        : Target a specific kube-context}';

    protected $description = 'Back up every database and irreplaceable volume, encrypted, off-site';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        // See BackupInitCommand: a backup taken against the wrong cluster is
        // the worst possible outcome, because it looks exactly like a good one.
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
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
        $driver = $this->commonsDatabaseDriver($kubectl);

        if ($driver === null) {
            $this->laraKubeError('No backup-capable Commons database found. Supported: PostgreSQL, MySQL, MariaDB.');

            return 1;
        }

        $databases = $this->backupDatabases($kubectl);

        if ($databases === []) {
            $this->laraKubeError("Found no databases to back up — is the Commons {$driver->value} reachable?");

            return 1;
        }

        $service = $driver->commonsServiceName();

        foreach ($databases as $db) {
            $this->withSpin("Dumping database {$db}...", function () use ($kubectl, $db, $work, $driver, $service, &$failures) {
                $result = Process::timeout(600)->run(
                    "{$kubectl} exec deploy/{$service} -n larakube-plex -c {$service} -- "
                    .'sh -c '.escapeshellarg($driver->commonsBackupCommand($db))
                    .' | gzip > '.escapeshellarg("{$work}/db-{$db}.sql.gz"),
                );

                if (! $result->successful() || $this->sizeOf("{$work}/db-{$db}.sql.gz") < 100) {
                    $failures[] = "database {$db}";
                }
            });
        }

        // 2. Volumes whose contents cannot be rebuilt from anything else.
        $volumeTargets = $this->backupVolumeTargets($kubectl);

        foreach ($volumeTargets as $target) {
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

        // 3. One encrypted object per item, then the manifest LAST.
        //
        //    Per item rather than one archive because restores are per item:
        //    recovering a 39KB database used to mean downloading the whole
        //    55MB archive, and the object store dominates that number while
        //    being the least likely thing to need a targeted restore.
        $prefix = $this->backupRunPrefix($stamp);
        $items = [];
        $total = 0;

        foreach ($this->producedItems($work) as $item) {
            $sealed = "{$work}/{$item['object']}";

            $ok = $this->withSpin("Encrypting and uploading {$item['name']}...", fn () => Process::timeout(600)->run(
                'openssl enc -aes-256-cbc -pbkdf2 -salt -pass '.escapeshellarg("pass:{$config['passphrase']}")
                .' -in '.escapeshellarg($item['path']).' -out '.escapeshellarg($sealed),
            )->successful() && Process::timeout(1800)->env($this->backupAwsEnv($config))->run(
                'aws --endpoint-url '.escapeshellarg($config['endpoint'])
                .' s3 cp '.escapeshellarg($sealed).' '.escapeshellarg("s3://{$config['bucket']}/{$prefix}{$item['object']}"),
            )->successful());

            if (! $ok) {
                // No manifest is written, so this prefix stays invisible to
                // backup:list and backup:restore. `backup:prune` sweeps it up.
                $this->laraKubeError("Failed to store {$item['kind']} {$item['name']} — this backup is incomplete and will not be listed.");
                $this->line("  <fg=gray>Working files left at {$work} for inspection.</>");

                return 1;
            }

            $bytes = $this->sizeOf($sealed);
            $total += $bytes;
            $items[] = [
                'kind' => $item['kind'],
                'name' => $item['name'],
                'object' => $item['object'],
                'bytes' => $bytes,
            ];
        }

        $manifest = "{$work}/manifest.json";
        file_put_contents($manifest, json_encode([
            'version' => 1,
            'taken_at' => gmdate('c'),
            'engine' => $driver->value,
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $committed = $this->withSpin('Committing the manifest...', fn () => Process::timeout(300)
            ->env($this->backupAwsEnv($config))->run(
                'aws --endpoint-url '.escapeshellarg($config['endpoint'])
                .' s3 cp '.escapeshellarg($manifest).' '.escapeshellarg("s3://{$config['bucket']}/".$this->manifestKey($stamp)),
            )->successful());

        if ($this->option('keep-local')) {
            $dest = rtrim((string) $this->option('keep-local'), '/');
            Process::run('cp -R '.escapeshellarg($work).'/. '.escapeshellarg("{$dest}/"));
        }

        Process::run('rm -rf '.escapeshellarg($work));

        if (! $committed) {
            $this->laraKubeError('The manifest failed to upload, so this backup does NOT count as taken.');
            $this->line('  <fg=gray>Its objects are at the destination but unlisted. Re-run, then');
            $this->line('  <fg=blue>larakube backup:prune</> <fg=gray>to clear the abandoned prefix.</>');

            return 1;
        }

        $size = $this->humanBytes($total);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Backup complete and off-site.');
        $this->newLine();
        $this->line('  <fg=gray>Databases:</> <fg=blue>'.count($databases).'</>');
        $this->line('  <fg=gray>Volumes:</>   <fg=blue>'.count($volumeTargets).'</>');
        $this->line("  <fg=gray>Size:</>      <fg=blue>{$size}</> <fg=gray>across ".count($items).' objects</>');
        $this->line("  <fg=gray>Stored:</>    <fg=blue>s3://{$config['bucket']}/{$prefix}</>");
        $this->newLine();
        $this->line('  <fg=gray>A backup you have never restored is a hypothesis. Try one:</>');
        $this->line('  <fg=blue>larakube backup:restore --deep</>');
        $this->newLine();

        return 0;
    }

    /**
     * The dumps and archives on disk, as manifest items.
     *
     * Reads the working directory rather than re-deriving from the inventory,
     * so the manifest can only ever describe files that actually exist.
     *
     * @return array<int, array{kind: string, name: string, path: string, object: string}>
     */
    protected function producedItems(string $work): array
    {
        $items = [];

        foreach ([['db-', '.sql.gz', 'database'], ['vol-', '.tar.gz', 'volume']] as [$prefix, $suffix, $kind]) {
            foreach (glob("{$work}/{$prefix}*{$suffix}") ?: [] as $path) {
                $items[] = [
                    'kind' => $kind,
                    'name' => substr(basename($path), strlen($prefix), -strlen($suffix)),
                    'path' => $path,
                    'object' => basename($path).'.enc',
                ];
            }
        }

        return $items;
    }
}
