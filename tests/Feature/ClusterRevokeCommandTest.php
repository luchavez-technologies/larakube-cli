<?php

/**
 * cluster:revoke teammate off-boarding — specifically the ClusterRoleBinding
 * gap: a --cluster grant (cluster:grant --cluster, potentially cluster-admin)
 * lives outside any namespace, so the old full-off-board RoleBinding sweep
 * never touched it, leaving cluster-wide access alive after "off-boarding".
 */

use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function () {
    Prompt::interactive(false);

    // Run outside any project so resolveClusterContext takes the standalone
    // (--context) branch.
    $this->tempDir = sys_get_temp_dir().'/larakube-clusterrevoke-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalDir);
    exec('rm -rf '.escapeshellarg($this->tempDir));
});

test('full off-board deletes the ClusterRoleBinding, not just namespaced RoleBindings', function () {
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

test('--cluster revokes only the cluster-wide grant, leaving per-namespace RoleBindings untouched', function () {
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
