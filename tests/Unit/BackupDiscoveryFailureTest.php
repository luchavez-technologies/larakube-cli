<?php

use App\Traits\InteractsWithBackup;
use Illuminate\Support\Facades\Process;

function backupDiscoveryProbe(): object
{
    return new class
    {
        use InteractsWithBackup;

        public function namespaces(): array
        {
            return $this->larakubeNamespaces('kubectl');
        }

        public function deployments(string $namespace): array
        {
            return $this->namespaceDeploymentNames('kubectl', $namespace);
        }

        public function targets(): array
        {
            return $this->backupVolumeTargets('kubectl');
        }
    };
}

test('a failed namespace listing raises rather than reading as zero namespaces', function (): void {
    // Empty output is exactly what a timeout or transient API error produces.
    // Treating it as "no namespaces" collapses discovery to the single
    // hardcoded target, which backup:schedule then bakes into the CronJob and
    // reports as a success — a nightly backup covering almost nothing.
    Process::fake(['*get namespace*' => Process::result(output: '', exitCode: 1), '*' => Process::result(output: '')]);

    expect(fn () => backupDiscoveryProbe()->namespaces())
        ->toThrow(RuntimeException::class, 'Could not list namespaces');
});

test('a failed deployment listing raises rather than emptying that namespace', function (): void {
    Process::fake(['*get deployment*' => Process::result(output: '', exitCode: 1), '*' => Process::result(output: '')]);

    expect(fn () => backupDiscoveryProbe()->deployments('larakube-shared'))
        ->toThrow(RuntimeException::class, "Could not list deployments in 'larakube-shared'");
});

test('a cluster that genuinely has no larakube namespaces is still fine', function (): void {
    // The distinction that makes the guard safe: succeeding with no output is
    // a real answer, and must not raise.
    Process::fake(['*get namespace*' => Process::result(output: 'default kube-system'), '*' => Process::result(output: '')]);

    expect(backupDiscoveryProbe()->namespaces())->toBe([]);
});

test('discovery collapsing to the hardcoded target cannot happen silently', function (): void {
    // The end-to-end shape of the bug: namespaces unreadable, yet
    // backupVolumeTargets() used to hand back seaweedfs alone.
    Process::fake(['*get namespace*' => Process::result(output: '', exitCode: 1), '*' => Process::result(output: '')]);

    expect(fn () => backupDiscoveryProbe()->targets())->toThrow(RuntimeException::class);
});
