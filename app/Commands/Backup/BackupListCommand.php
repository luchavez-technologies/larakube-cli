<?php

namespace App\Commands\Backup;

use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

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

        $runs = $this->listBackupRuns($config);
        $complete = array_filter($runs, fn (array $r) => $r['complete']);
        $partial = count($runs) - count($complete);

        $rows = [];
        foreach (array_reverse($complete, true) as $run) {
            $rows[] = [
                $run['taken'],
                $this->humanBytes($run['bytes']),
                (string) (count($run['objects']) - 1).' objects',
                $run['stamp'],
            ];
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

        table(['Taken', 'Size', 'Contents', 'Backup'], $rows);

        $this->newLine();
        $this->line('  <fg=gray>Destination:</> <fg=blue>'.$config['endpoint'].'/'.$config['bucket'].'</>');

        if ($partial > 0) {
            // Not an error: a run that died mid-upload leaves objects with no
            // manifest, and refusing to list it is the point. Say so anyway,
            // because otherwise it is storage nobody can see or account for.
            $this->newLine();
            $this->laraKubeWarn("{$partial} incomplete backup".($partial === 1 ? '' : 's').' at the destination (no manifest — never listed, never restorable).');
            $this->line('  <fg=gray>Clear them with</> <fg=blue>larakube backup:prune</><fg=gray>.</>');
        }

        $this->newLine();

        return 0;
    }
}
