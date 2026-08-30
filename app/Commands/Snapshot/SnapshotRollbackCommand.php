<?php

namespace App\Commands\Snapshot;

use App\Traits\CheckPrerequisites;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

/**
 * NOT IMPLEMENTED, and refuses rather than pretending.
 *
 * It previously printed "✅ PVC 'x' successfully restored" having done nothing
 * at all -- no manifest, no kubectl call -- and defaulted the PVC name to the
 * literal string 'target-pvc' when the argument was omitted. On a command whose
 * entire job is protecting data, a false success is worse than no command.
 *
 * Filling it in is not a matter of adding the missing apply. A bound PVC's
 * spec.dataSource is immutable, so a snapshot cannot be restored ONTO an
 * existing volume. The real operation is destructive and multi-step: scale the
 * workload to zero, delete the PVC, recreate it from the snapshot, scale back
 * up -- with the window in between being the only chance to notice the snapshot
 * was never readyToUse. That needs designing, not guessing, so this refuses
 * until it is designed.
 *
 * `snapshot:clone` covers the safe half today: restore a snapshot into a NEW
 * volume and repoint the workload, losing nothing if it turns out wrong.
 */
class SnapshotRollbackCommand extends Command
{
    use CheckPrerequisites, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'snapshot:rollback
        {snapshot? : The name of the VolumeSnapshot to restore from}
        {--pvc= : The existing PersistentVolumeClaim to restore onto}';

    protected $description = 'Restore a VolumeSnapshot onto an existing volume (not yet implemented)';

    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeError('snapshot:rollback is not implemented.');
        $this->newLine();
        $this->line("  <fg=gray>A bound PVC's dataSource is immutable, so a snapshot cannot be restored</>");
        $this->line('  <fg=gray>onto a volume that already exists. Doing it properly means scaling the</>');
        $this->line('  <fg=gray>workload down, deleting the PVC, recreating it from the snapshot and</>');
        $this->line('  <fg=gray>scaling back up — destructive enough to be worth designing rather than</>');
        $this->line('  <fg=gray>guessing at.</>');
        $this->newLine();
        $this->line('  <fg=gray>Until then, restore into a NEW volume and repoint the workload:</>');
        $this->line('  <fg=blue>  larakube snapshot:clone <snapshot> --pvc=<new-name></>');
        $this->newLine();

        return 1;
    }
}
