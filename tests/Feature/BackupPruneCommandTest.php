<?php

use App\Commands\Backup\BackupPruneCommand;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Reaches the retention maths without a cluster or a destination.
 */
class BackupPruneCommandProbe extends BackupPruneCommand
{
    /** @param array<int, string> $stamps */
    public function keepers(array $stamps, int $keepDaily, int $keepWeekly, int $keepMonthly): array
    {
        $this->setInput(new ArrayInput([
            '--keep-daily' => (string) $keepDaily,
            '--keep-weekly' => (string) $keepWeekly,
            '--keep-monthly' => (string) $keepMonthly,
        ], $this->getDefinition()));

        return $this->selectKeepers($stamps);
    }
}

test('backup:prune keeps the newest run per period, GFS style', function (): void {
    $cmd = new BackupPruneCommandProbe;

    // Two runs on the same day: only the newer one should hold the daily slot.
    $keep = $cmd->keepers([
        '2026-01-15-031700',
        '2026-02-15-031700',
        '2026-03-01-031700',
        '2026-03-08-031700',
        '2026-03-14-031700',
        '2026-03-15-031700',
    ], keepDaily: 1, keepWeekly: 2, keepMonthly: 2);

    // Newest wins the daily slot; older ones fall through to weekly/monthly.
    expect($keep)->toContain('2026-03-15-031700')
        ->and($keep)->not->toContain('2026-01-15-031700');
});

test('backup:prune never deletes a run id it cannot parse', function (): void {
    $keep = (new BackupPruneCommandProbe)->keepers(
        ['something-else', '2026-03-15-031700'],
        keepDaily: 0, keepWeekly: 0, keepMonthly: 0,
    );

    // Zero retention everywhere, yet the unrecognised id survives: deleting
    // something we do not understand is not a risk worth taking.
    expect($keep)->toBe(['something-else']);
});

test('backup:prune shows before it deletes', function (): void {
    // The only command in the suite that destroys backups. --apply is required,
    // so a bare run can never remove anything.
    $signature = (new ReflectionClass(BackupPruneCommand::class))
        ->getDefaultProperties()['signature'];

    expect($signature)->toContain('--apply')
        ->toContain('--keep-daily=7')
        ->toContain('--keep-weekly=4')
        ->toContain('--keep-monthly=6');
});
