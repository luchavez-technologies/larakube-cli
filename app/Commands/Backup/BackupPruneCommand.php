<?php

namespace App\Commands\Backup;

use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Delete backups nothing needs any more.
 *
 * Grandfather-father-son: keep the last N daily, then one per week, then one
 * per month. The point is that recency and depth want different things — you
 * want yesterday's backup precisely, and you want *a* backup from March
 * without caring which day.
 *
 * A separate command from `backup:schedule` on purpose, and it defaults to
 * showing rather than deleting. This is the only command in the suite that
 * destroys backups, which is the one thing the suite exists to prevent.
 */
class BackupPruneCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'backup:prune
        {environment=local : Environment whose backups to prune}
        {--keep-daily=7    : Keep every backup from the last N days}
        {--keep-weekly=4   : Then keep the newest backup of each of the last N weeks}
        {--keep-monthly=6  : Then keep the newest backup of each of the last N months}
        {--apply           : Actually delete. Without this the command only shows what would go.}
        {--force           : Skip the confirmation prompt}
        {--context=        : Target a specific kube-context}';

    protected $description = 'Delete old backups on a grandfather-father-son schedule';

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

        if ($runs === []) {
            $this->laraKubeWarn('Nothing at the destination to prune.');
            $this->newLine();

            return 0;
        }

        $keep = $this->selectKeepers(array_keys(array_filter($runs, fn (array $r) => $r['complete'])));
        $drop = [];

        foreach ($runs as $stamp => $run) {
            if (in_array($stamp, $keep, true)) {
                continue;
            }

            // Incomplete runs are unrestorable by definition — no manifest
            // means backup:restore refuses them — so they are pure cost. They
            // are the reason this command should be run after a failed backup.
            $drop[$stamp] = $run;
        }

        $rows = [];
        foreach (array_reverse($runs, true) as $stamp => $run) {
            $rows[] = [
                $run['taken'],
                $this->humanBytes($run['bytes']),
                $run['complete'] ? '' : 'no manifest',
                isset($drop[$stamp]) ? '<fg=red>delete</>' : '<fg=green>keep</>',
                $stamp,
            ];
        }

        table(['Taken', 'Size', '', '', 'Backup'], $rows);
        $this->newLine();

        if ($drop === []) {
            $this->laraKubeInfo('Nothing to prune — every backup is still within the retention window.');
            $this->newLine();

            return 0;
        }

        $freed = $this->humanBytes(array_sum(array_column($drop, 'bytes')));
        $this->line('  <fg=gray>Would delete</> <fg=blue>'.count($drop).'</> <fg=gray>backups, freeing</> <fg=blue>'.$freed.'</>');
        $this->line('  <fg=gray>Keeping</> <fg=blue>'.count($keep).'</> <fg=gray>('.$this->describeRetention().')</>');
        $this->newLine();

        // Showing is the default. Deleting backups is the one irreversible
        // thing this suite can do, so it takes an explicit flag rather than a
        // prompt someone can tab through.
        if (! $this->option('apply')) {
            $this->line('  <fg=gray>Nothing was deleted. Re-run with</> <fg=blue>--apply</> <fg=gray>to do it.</>');
            $this->newLine();

            return 0;
        }

        if (! $this->confirmDestructive(array_merge(
            ['Permanently delete '.count($drop).' backups from '.$config['bucket'].':'],
            array_map(fn (string $s) => "  {$s}", array_slice(array_keys($drop), 0, 10)),
            count($drop) > 10 ? ['  … and '.(count($drop) - 10).' more'] : [],
            ['This cannot be undone. The remaining '.count($keep).' backups are untouched.'],
        ))) {
            return 1;
        }

        $failed = [];

        foreach ($drop as $stamp => $run) {
            $ok = $this->withSpin("Deleting {$stamp}...", fn () => Process::timeout(600)
                ->env($this->backupAwsEnv($config))->run(
                    'aws --endpoint-url '.escapeshellarg($config['endpoint'])
                    .' s3 rm --recursive '.escapeshellarg("s3://{$config['bucket']}/".$this->backupRunPrefix($stamp)),
                )->successful());

            if (! $ok) {
                $failed[] = $stamp;
            }
        }

        $this->laraKubeNewLine();

        if ($failed !== []) {
            $this->laraKubeError('Could not delete: '.implode(', ', $failed));
            $this->newLine();

            return 1;
        }

        $this->laraKubeInfo('✅ Pruned '.count($drop).' backups, freeing '.$freed.'.');
        $this->newLine();

        return 0;
    }

    /**
     * Which runs survive, under grandfather-father-son.
     *
     * Each tier claims the NEWEST run in its period, and a run already claimed
     * by a tighter tier does not consume a slot in a looser one — otherwise a
     * busy week would eat the whole weekly allowance and leave nothing older.
     *
     * @param  array<int, string>  $stamps  completed run ids, ascending
     * @return array<int, string>
     */
    protected function selectKeepers(array $stamps): array
    {
        $daily = max(0, (int) $this->option('keep-daily'));
        $weekly = max(0, (int) $this->option('keep-weekly'));
        $monthly = max(0, (int) $this->option('keep-monthly'));

        $keep = [];
        $claimed = [];

        // Newest first: every tier wants the most recent run in its period.
        foreach (array_reverse($stamps) as $stamp) {
            $time = $this->stampTime($stamp);

            if ($time === null) {
                // Unparseable id — keep it rather than delete something we do
                // not understand.
                $keep[] = $stamp;

                continue;
            }

            foreach ([['day', 'Y-m-d', $daily], ['week', 'o-\WW', $weekly], ['month', 'Y-m', $monthly]] as [$tier, $format, $limit]) {
                $period = date($format, $time);

                if (isset($claimed[$tier][$period]) || count($claimed[$tier] ?? []) >= $limit) {
                    continue;
                }

                $claimed[$tier][$period] = $stamp;
                $keep[] = $stamp;

                break;
            }
        }

        return array_values(array_unique($keep));
    }

    /** Run ids are `Y-m-d-His`; anything else is not ours. */
    protected function stampTime(string $stamp): ?int
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})$/', $stamp, $m)) {
            return null;
        }

        return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]) ?: null;
    }

    protected function describeRetention(): string
    {
        return sprintf(
            '%d daily, %d weekly, %d monthly',
            (int) $this->option('keep-daily'),
            (int) $this->option('keep-weekly'),
            (int) $this->option('keep-monthly'),
        );
    }
}
