<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Fakes for a claim that exists at $current in larakube-shared.
 *
 * Patterns are ordered specific-first: Process::fake matches the first key
 * that fits, and three different calls here are all `kubectl get pvc ...`.
 */
function storageResizeFakes(string $current = '10Gi', bool $expandable = false, string $conditions = ''): array
{
    return [
        '*storageClassName*' => Process::result(output: 'local-path'),
        '*status.conditions*' => Process::result(output: $conditions),
        '*get pvc --all-namespaces*' => Process::result(output: "larakube-shared\n"),
        '*allowVolumeExpansion*' => Process::result(output: $expandable ? 'true' : ''),
        '*is-default-class*' => Process::result(output: "local-path\n"),
        '*get pvc -n *' => Process::result(output: "drive-ocis-storage={$current}\n"),
        '*get deployments*' => Process::result(output: "drive-ocis\n"),
        '*patch pvc*' => Process::result(output: 'persistentvolumeclaim/drive-ocis-storage patched'),
        '*' => Process::result(output: ''),
    ];
}

test('storage:resize refuses when the StorageClass cannot expand', function (): void {
    Http::fake();
    Process::fake(storageResizeFakes(expandable: false));

    $this->artisan('storage:resize local --pvc=drive-ocis-storage --size=20Gi --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('does not support volume expansion');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'patch pvc'));
});

test('storage:resize refuses to shrink a claim', function (): void {
    Http::fake();
    Process::fake(storageResizeFakes(current: '10Gi', expandable: true));

    $this->artisan('storage:resize local --pvc=drive-ocis-storage --size=5Gi --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('A volume can only grow');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'patch pvc'));
});

test('storage:resize refuses an unparseable size', function (): void {
    Http::fake();
    Process::fake(storageResizeFakes(expandable: true));

    $this->artisan('storage:resize local --pvc=drive-ocis-storage --size=banana --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Pass a valid --size');
});

test('storage:resize refuses a claim that does not exist', function (): void {
    Http::fake();
    Process::fake([
        '*get pvc --all-namespaces*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('storage:resize local --pvc=nope --size=20Gi --force')
        ->assertExitCode(1)
        ->expectsOutputToContain("No PersistentVolumeClaim named 'nope'");
});

test('storage:resize patches the claim when the StorageClass expands', function (): void {
    Http::fake();
    Process::fake(storageResizeFakes(current: '10Gi', expandable: true));

    $this->artisan('storage:resize local --pvc=drive-ocis-storage --size=20Gi --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('now requests 20Gi');

    Process::assertRan(fn ($process) => str_contains($process->command, 'patch pvc drive-ocis-storage -n larakube-shared')
        && str_contains($process->command, '20Gi'));
});

test('storage:resize never scales anything, let alone the whole namespace', function (): void {
    // The command this replaced ran `kubectl scale deployment --all --replicas=0`
    // against the namespace — against larakube-shared that is a full-fleet
    // outage to touch one volume. Resizing must not stop a single workload.
    Http::fake();
    Process::fake(storageResizeFakes(current: '10Gi', expandable: true));

    $this->artisan('storage:resize local --pvc=drive-ocis-storage --size=20Gi --force')
        ->assertExitCode(0);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'scale'));
});

test('storage:resize says the filesystem grows on restart when the driver defers it', function (): void {
    Http::fake();
    Process::fake(storageResizeFakes(current: '10Gi', expandable: true, conditions: 'FileSystemResizePending'));

    $this->artisan('storage:resize local --pvc=drive-ocis-storage --size=20Gi --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('grows when the pod restarts');
});
