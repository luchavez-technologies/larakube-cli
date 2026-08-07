<?php

namespace App\Commands\Backup;

use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Deploy the nightly backup CronJob.
 *
 * Separate from `backup:init` on purpose. Configuring a destination writes one
 * Secret; scheduling puts a recurring workload in the cluster with a
 * ServiceAccount that can exec into pods. Different blast radii, so different
 * commands — and `backup:unschedule` is a real command rather than a flag here.
 */
class BackupScheduleCommand extends Command
{
    use DeploysClusterTool, InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, RequiresFlagsWhenNonInteractive, StreamsProcessOutput;

    /**
     * Off the hour on purpose — :00 and :30 are where every cron on earth
     * lands — and after chat-media-prune's 02:41, which writes to SeaweedFS
     * while this job archives SeaweedFS's data directory.
     */
    protected const DEFAULT_SCHEDULE = '17 3 * * *';

    protected $signature = 'backup:schedule
        {environment=local : Environment whose cluster to schedule backups on}
        {--cron= : Cron expression, read in --timezone. Prompts with common schedules when omitted.}
        {--timezone= : IANA timezone the schedule is read in (defaults to yours). Needs Kubernetes >= 1.27.}
        {--context= : Target a specific kube-context}';

    protected $description = 'Deploy the nightly backup CronJob into the cluster';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->backupKubectl($context);
        $ns = $this->backupNamespace();

        // Scheduling a job that has nowhere to upload would fail nightly and
        // quietly, which is the worst shape a backup problem can take.
        if ($this->readBackupConfig($kubectl, $ns) === null) {
            $this->laraKubeError('No backup destination configured. Run `larakube backup:init` first.');

            return 1;
        }

        $schedule = $this->resolveSchedule();
        // Kubernetes reads a bare schedule in the controller-manager's
        // timezone — UTC almost everywhere — so "3am" silently becomes 11am in
        // Manila. Default to the operator's own zone and always show the result.
        $timezone = (string) ($this->option('timezone') ?: $this->detectTimezone());

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->laraKubeError("'{$timezone}' is not a known IANA timezone (e.g. Asia/Manila, Europe/London, UTC).");

            return 1;
        }

        $volumes = $this->backupVolumeTargets();

        $manifest = view('k8s.backup.cronjob', [
            'schedule' => $schedule,
            'timezone' => $timezone,
            'volumes' => $volumes,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-backup-cron.yaml';
        file_put_contents($tmp, $manifest);

        $ok = $this->withSpin('Deploying the backup CronJob...', fn () => Process::timeout(300)->run(
            "{$kubectl} apply -f {$tmp}",
        )->successful());

        @unlink($tmp);

        if (! $ok) {
            $this->laraKubeError('Failed to deploy the CronJob.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Nightly backups scheduled.');
        $this->newLine();
        $this->line('  <fg=gray>Runs at:</>   <fg=blue>'.$this->describeSchedule($schedule, $timezone).'</>');
        $this->line("  <fg=gray>Cron:</>      <fg=blue>{$schedule}</>");
        $this->line('  <fg=gray>Covers:</>    <fg=blue>'.count($volumes).' volumes + every database</>');
        $this->newLine();
        if (($growth = $this->describeGrowth($schedule)) !== null) {
            // No pruning exists yet, so every archive accumulates. R2's free
            // tier is 10GB; better to see that now than at the overage email.
            $this->line("  <fg=gray>Growth:</>    <fg=blue>{$growth}</> <fg=gray>(nothing is pruned yet)</>");
            $this->newLine();
        }

        // Named explicitly rather than left in a manifest nobody reads: this is
        // the one permission that makes the in-cluster design possible, and it
        // is close to root inside those pods.
        $this->laraKubeWarn('This grants pods/exec in: '.$this->namespaceList($volumes));
        $this->line('  <fg=gray>The job reads each tool\'s data by exec-ing into its pod, which avoids');
        $this->line('  mounting six volumes but is effectively root inside them. It is bound to');
        $this->line('  those namespaces only.</>');
        $this->newLine();
        $this->line('  <fg=gray>Watch the first run:</>  <fg=blue>kubectl get jobs -n larakube-shared -w</>');
        $this->line("  <fg=gray>Run one now:</>          <fg=blue>kubectl create job --from=cronjob/larakube-backup backup-now -n {$ns}</>");
        $this->line("  <fg=gray>Stop scheduling:</>      <fg=blue>larakube backup:unschedule {$env}</>");
        $this->newLine();
        $this->line('  <fg=gray>Scheduling is not verification. Prove it still restores:</>');
        $this->line("  <fg=blue>larakube backup:restore {$env}</>");
        $this->newLine();

        return 0;
    }

    /**
     * The schedule, chosen from common shapes rather than hand-written cron.
     *
     * Cron is easy to get subtly wrong and hard to eyeball, and the cost of a
     * mistake here is either no backups or backups during business hours.
     */
    protected function resolveSchedule(): string
    {
        $explicit = (string) ($this->option('cron') ?? '');

        if ($explicit !== '') {
            return $explicit;
        }

        // Non-interactive: a sane nightly default beats refusing to schedule.
        if ($this->cannotPrompt()) {
            return self::DEFAULT_SCHEDULE;
        }

        $choice = select(
            label: 'How often should backups run?',
            options: [
                self::DEFAULT_SCHEDULE => 'Every night at 03:17          (recommended)',
                '17 3,15 * * *' => 'Twice a day, 03:17 and 15:17',
                '17 */6 * * *' => 'Every 6 hours',
                '17 3 * * 0' => 'Weekly, Sunday at 03:17',
                'custom' => 'Write my own cron expression',
            ],
            default: self::DEFAULT_SCHEDULE,
            hint: 'Times are in your timezone — the exact minute is off the hour on purpose.',
        );

        if ($choice !== 'custom') {
            return $choice;
        }

        return (string) text(
            label: 'Cron expression',
            placeholder: '17 3 * * *',
            required: true,
            hint: 'minute hour day-of-month month day-of-week',
        );
    }

    /**
     * Rough monthly storage at this frequency, so the choice is made with its
     * cost visible. Matters more than usual right now: there is no pruning yet,
     * so every archive accumulates and R2's free tier is 10 GB.
     */
    protected function describeGrowth(string $cron, int $archiveMb = 55): ?string
    {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];

        if (count($parts) !== 5) {
            return null;
        }

        [$min, $hour, $dom, $mon, $dow] = $parts;

        $perDay = match (true) {
            str_starts_with($hour, '*/') => intdiv(24, max(1, (int) substr($hour, 2))),
            str_contains($hour, ',') => count(explode(',', $hour)),
            default => 1,
        };

        $perMonth = $dow !== '*' ? ($perDay * 4.3) : ($perDay * 30);
        $gb = round(($perMonth * $archiveMb) / 1024, 1);

        return sprintf('~%s archives/month, ~%sGB stored', round($perMonth), $gb);
    }

    /** @param array<int, array<string, string>> $volumes */
    protected function namespaceList(array $volumes): string
    {
        return collect($volumes)->pluck('namespace')->push('larakube-plex')->unique()->sort()->implode(', ');
    }
}
