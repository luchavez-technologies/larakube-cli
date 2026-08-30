<?php

/**
 * The snapshot commands, after they stopped lying.
 *
 * All three previously printed a ✅ having applied nothing: create and clone
 * rendered a manifest with $this->line() and never called kubectl, and rollback
 * did literally nothing — no manifest, no command — while defaulting its PVC
 * name to the literal string 'target-pvc'. On commands whose whole job is
 * protecting data, a false success is worse than no command: you take the
 * snapshot before the risky migration, see the tick, and have nothing.
 */

use Illuminate\Support\Facades\Process;

test('snapshot:create actually applies a VolumeSnapshot, in the project namespace', function (): void {
    Process::fake([
        '*get pvc *' => Process::result(output: 'data-pvc   Bound   pvc-1   50Gi'),
        '*apply -f *' => Process::result(output: 'volumesnapshot.snapshot.storage.k8s.io/snap created'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('snapshot:create', ['pvc' => 'data-pvc', '--name' => 'snap'])
        ->assertExitCode(0)
        ->expectsOutputToContain('created');

    Process::assertRan(fn ($p) => str_contains($p->command, 'apply -f'));
});

test('snapshot:create refuses a PVC that does not exist', function (): void {
    // Snapshotting a missing PVC yields a VolumeSnapshot stuck at
    // readyToUse=false forever, which reads as a slow snapshot, not a typo.
    Process::fake([
        '*get pvc *' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('snapshot:create', ['pvc' => 'nope', '--name' => 'snap'])
        ->assertExitCode(1)
        ->expectsOutputToContain('No PersistentVolumeClaim');

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'apply -f'));
});

test('snapshot:clone refuses a PVC name that already exists', function (): void {
    // --pvc names a volume being CREATED. Applying onto a live PVC is a no-op
    // that reports success, leaving the operator believing a restore happened
    // to a volume still holding its old data.
    Process::fake([
        '*get pvc *' => Process::result(output: 'restored   Bound   pvc-9   50Gi'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('snapshot:clone', ['snapshot' => 'snap', '--pvc' => 'restored'])
        ->assertExitCode(1)
        ->expectsOutputToContain('already exists');

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'apply -f'));
});

test('snapshot:clone applies a new PVC sourced from the snapshot', function (): void {
    Process::fake([
        '*get pvc *' => Process::result(output: ''),
        '*apply -f *' => Process::result(output: 'persistentvolumeclaim/restored created'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('snapshot:clone', ['snapshot' => 'snap', '--pvc' => 'restored'])
        ->assertExitCode(0)
        ->expectsOutputToContain('created');

    Process::assertRan(fn ($p) => str_contains($p->command, 'apply -f'));
});

test('snapshot:rollback refuses instead of reporting a restore it never performed', function (): void {
    // A bound PVC's dataSource is immutable, so this cannot be an apply. Until
    // the scale-down/delete/recreate flow is designed, refusing is the honest
    // answer — and a non-zero exit means a script cannot mistake it for done.
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('snapshot:rollback', ['snapshot' => 'snap', '--pvc' => 'data-pvc'])
        ->assertExitCode(1)
        ->expectsOutputToContain('not implemented');

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'apply -f'));
});
