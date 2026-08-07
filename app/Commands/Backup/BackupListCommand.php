<?php

namespace App\Commands\Backup;

use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class BackupListCommand extends Command
{
    use DeploysClusterTool, InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'backup:list
        {environment=local : Environment whose backups to list}
        {--context= : Target a specific kube-context}';

    protected $description = 'List the backups stored at the off-site destination';

    public function handle(): int
    {
        $this->renderHeader();

        $kubectl = $this->backupKubectl($this->resolveToolContext(
            (string) $this->argument('environment'),
            (string) $this->option('context') ?: null,
        ));
        $config = $this->readBackupConfig($kubectl, $this->backupNamespace());

        if ($config === null) {
            $this->laraKubeError('No backup destination configured. Run `larakube backup:init` first.');

            return 1;
        }

        $out = Process::timeout(120)->env($this->backupAwsEnv($config))->run(
            'aws --endpoint-url '.escapeshellarg($config['endpoint'])
            .' s3 ls '.escapeshellarg("s3://{$config['bucket']}/larakube/"),
        )->output();

        $rows = [];
        foreach (array_filter(array_map('trim', explode("\n", $out))) as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 4) {
                continue;
            }
            $rows[] = ["{$parts[0]} {$parts[1]}", $this->humanBytes((int) $parts[2]), $parts[3]];
        }

        if ($rows === []) {
            $this->laraKubeWarn('No backups found at the destination.');
            $this->newLine();
            $this->line('  <fg=gray>An empty bucket after a successful `backup:run` usually means the');
            $this->line('  credentials can write but not list. Worth resolving now rather than');
            $this->line('  during a restore.</>');
            $this->newLine();

            return 0;
        }

        table(['Taken', 'Size', 'Object'], array_reverse($rows));

        $this->newLine();
        $this->line('  <fg=gray>Destination:</> <fg=blue>'.$config['endpoint'].'/'.$config['bucket'].'</>');
        $this->newLine();

        return 0;
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
