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
 * Remove the nightly backup CronJob and the exec permission it needed.
 *
 * A real command, not a `--remove` flag on backup:schedule: stopping automated
 * backups is a distinct decision with its own consequence, and it should be
 * possible to say without touching the destination config.
 *
 * The destination Secret and everything already in the bucket are left alone —
 * unscheduling means "stop taking new backups", never "discard the old ones".
 */
class BackupUnscheduleCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'backup:unschedule
        {environment=local : Environment whose cluster to stop scheduling on}
        {--force   : Skip the confirmation prompt}
        {--context= : Target a specific kube-context}';

    protected $description = 'Remove the nightly backup CronJob';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->backupKubectl($context);
        $ns = $this->backupNamespace();

        $exists = trim(Process::run(
            "{$kubectl} get cronjob larakube-backup -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';

        if (! $exists) {
            $this->laraKubeInfo('No backup CronJob is scheduled — nothing to do.');

            return 0;
        }

        if (! $this->confirmDestructive([
            'Stop taking automated backups on this cluster.',
            'Existing backups and the destination config are left untouched.',
            'Nothing will run nightly until `backup:schedule` is run again.',
        ])) {
            return 0;
        }

        $namespaces = collect($this->backupVolumeTargets())
            ->pluck('namespace')->push('larakube-plex')->unique()->sort();

        $ok = $this->withSpin('Removing the backup CronJob...', function () use ($kubectl, $ns, $namespaces) {
            $result = Process::run(
                "{$kubectl} delete cronjob/larakube-backup serviceaccount/larakube-backup -n {$ns} --ignore-not-found",
            );

            // The exec grant is the point of the whole design; leaving it behind
            // would keep a standing permission with nothing using it.
            foreach ($namespaces as $namespace) {
                Process::run("{$kubectl} delete rolebinding/larakube-backup -n {$namespace} --ignore-not-found");
            }

            Process::run("{$kubectl} delete clusterrole/larakube-backup --ignore-not-found");

            return $result->successful();
        });

        if (! $ok) {
            $this->laraKubeError('Failed to remove the CronJob.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Automated backups stopped.');
        $this->newLine();
        $this->line('  <fg=gray>Existing backups are untouched:</> <fg=blue>larakube backup:list '.$env.'</>');
        $this->line("  <fg=gray>Take one by hand:</>              <fg=blue>larakube backup:run {$env}</>");
        $this->newLine();

        return 0;
    }
}
