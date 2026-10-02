<?php

namespace App\Commands\Backup;

use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithBackup;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only: is this cluster backed up, where to, on what schedule, and when
 * did a backup last finish. GUIs read this instead of scraping `backup:list`.
 * Never prints the access keys or the passphrase.
 */
class BackupStatusCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithBackup, InteractsWithClusterContext, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'backup:status
        {environment=local : Environment whose backup state to read}
        {--context= : Target a specific kube-context}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Show whether backups are set up, their schedule, and the last one taken';

    public function handle(): int
    {
        $json = (bool) $this->flag('json');

        if ($json) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $kubectl = Kubectl::forContext($this->resolveToolContext(
            (string) $this->argument('environment'),
            (string) $this->option('context') ?: null,
        ))->prefix();
        $ns = $this->backupNamespace();
        $config = $this->readBackupConfig($kubectl, $ns);

        if ($config === null) {
            $status = ['success' => true, 'configured' => false];

            return $this->report($status, $json);
        }

        $status = [
            'success' => true,
            'configured' => true,
            'destination' => ['endpoint' => $config['endpoint'], 'bucket' => $config['bucket'], 'region' => $config['region']],
            'schedule' => $this->scheduleOf($kubectl, $ns),
            'backups' => $this->backupsAt($config),
            'recoveryCard' => $this->recoveryCard(),
        ];

        return $this->report($status, $json);
    }

    /**
     * @return array{scheduled: bool, cron: ?string, timezone: ?string, suspended: bool, lastScheduleTime: ?string, lastSuccessfulTime: ?string}
     */
    protected function scheduleOf(string $kubectl, string $ns): array
    {
        $out = trim(Process::run("{$kubectl} get cronjob larakube-backup -n {$ns} -o json --ignore-not-found")->output());
        $job = $out === '' ? null : json_decode($out, true);

        if (! is_array($job)) {
            return ['scheduled' => false, 'cron' => null, 'timezone' => null, 'suspended' => false, 'lastScheduleTime' => null, 'lastSuccessfulTime' => null];
        }

        return [
            'scheduled' => true,
            'cron' => $job['spec']['schedule'] ?? null,
            'timezone' => $job['spec']['timeZone'] ?? null,
            'suspended' => (bool) ($job['spec']['suspend'] ?? false),
            'lastScheduleTime' => $job['status']['lastScheduleTime'] ?? null,
            'lastSuccessfulTime' => $job['status']['lastSuccessfulTime'] ?? null,
        ];
    }

    /**
     * What is at the destination. Reading it needs the `aws` CLI, so a machine
     * without it reports `available: false` rather than claiming there are none.
     *
     * @param  array<string, string>  $config
     * @return array{available: bool, count: int, incomplete: int, last: ?array{id: string, taken: string, bytes: int, items: int}}
     */
    protected function backupsAt(array $config): array
    {
        if (trim(Process::run('command -v aws')->output()) === '') {
            return ['available' => false, 'count' => 0, 'incomplete' => 0, 'last' => null];
        }

        $runs = $this->listBackupRuns($config);
        $complete = array_filter($runs, fn (array $run): bool => $run['complete']);
        $last = $complete === [] ? null : $complete[array_key_last($complete)];

        return [
            'available' => true,
            'count' => count($complete),
            'incomplete' => count($runs) - count($complete),
            'last' => $last === null ? null : [
                'id' => $last['stamp'],
                'taken' => $last['taken'],
                'bytes' => $last['bytes'],
                'items' => count($last['objects']) - 1,
            ],
        ];
    }

    /** @return array{exists: bool, path: string} */
    protected function recoveryCard(): array
    {
        $path = home_path('.larakube/backup-recovery.txt');

        return ['exists' => is_file($path), 'path' => $path];
    }

    /** @param  array<string, mixed>  $status */
    private function report(array $status, bool $json): int
    {
        if ($json) {
            $this->jsonOutput($status);

            return 0;
        }

        if (! $status['configured']) {
            $this->laraKubeWarn('No backup destination configured. Run `larakube backup:init` first.');

            return 0;
        }

        $schedule = $status['schedule'];
        $backups = $status['backups'];

        table(['', ''], [
            ['Destination', $status['destination']['endpoint'].'/'.$status['destination']['bucket']],
            ['Schedule', $schedule['scheduled'] ? ($schedule['suspended'] ? 'paused' : "{$schedule['cron']} ({$schedule['timezone']})") : 'not scheduled'],
            ['Last scheduled run', $schedule['lastSuccessfulTime'] ?? 'never'],
            ['Backups kept', $backups['available'] ? (string) $backups['count'] : 'unknown (aws CLI missing)'],
            ['Latest', $backups['last'] === null ? 'none' : "{$backups['last']['taken']} ({$this->humanBytes($backups['last']['bytes'])})"],
            ['Recovery card', $status['recoveryCard']['exists'] ? $status['recoveryCard']['path'] : 'not found'],
        ]);

        return 0;
    }
}
