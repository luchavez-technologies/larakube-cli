<?php

/**
 * cluster:revoke teammate off-boarding — specifically the ClusterRoleBinding
 * gap: a --cluster grant (cluster:grant --cluster, potentially cluster-admin)
 * lives outside any namespace, so the old full-off-board RoleBinding sweep
 * never touched it, leaving cluster-wide access alive after "off-boarding".
 */

use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;

beforeEach(function (): void {
    Prompt::interactive(false);

    // Run outside any project so resolveClusterContext takes the standalone
    // (--context) branch.
    $this->temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $this->tempDir = $this->temporaryDirectory->path();
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function (): void {
    chdir($this->originalDir);
    $this->temporaryDirectory->delete();
});

test('full off-board deletes the ClusterRoleBinding, not just namespaced RoleBindings', function (): void {
    Process::fake([
        '*get rolebinding -A*' => Process::result(output: ''),
        '*delete clusterrolebinding*' => Process::result(output: ''),
        '*delete serviceaccount*' => Process::result(output: ''),
    ]);

    $this->artisan('cluster:revoke', [
        '--name' => 'lloyd',
        '--context' => 'do-nyc1-blue',
        '--force' => true,
    ])->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete clusterrolebinding')
        && str_contains($process->command, 'larakube-cluster-user-lloyd'));
});

test('--cluster revokes only the cluster-wide grant, leaving per-namespace RoleBindings untouched', function (): void {
    Process::fake([
        '*delete clusterrolebinding*' => Process::result(output: ''),
    ]);

    $this->artisan('cluster:revoke', [
        '--name' => 'lloyd',
        '--cluster' => true,
        '--context' => 'do-nyc1-blue',
        '--force' => true,
    ])->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete clusterrolebinding')
        && str_contains($process->command, 'larakube-cluster-user-lloyd'));

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'delete rolebinding')
        || str_contains($process->command, 'delete serviceaccount'));
});
