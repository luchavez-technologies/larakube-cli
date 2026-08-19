<?php

/**
 * kustomizeHandlesMultiDocPatches() writes a real probe overlay to a temp dir
 * then shells out to build it — this fakes the build command itself via
 * Process::fake() (a wildcard, since the probe dir name is randomized) rather
 * than requiring a real kustomize/kubectl on the test machine.
 */

use App\Traits\InteractsWithKustomize;
use Illuminate\Support\Facades\Process;

function kustomizeProbe(): object
{
    return new class
    {
        use InteractsWithKustomize;

        public function handles(string $buildPrefix): bool
        {
            return $this->kustomizeHandlesMultiDocPatches($buildPrefix);
        }
    };
}

test('kustomizeHandlesMultiDocPatches is true when the build succeeds with non-empty output', function (): void {
    Process::fake(['kubectl kustomize *' => "apiVersion: apps/v1\nkind: Deployment\n"]);

    expect(kustomizeProbe()->handles('kubectl kustomize'))->toBeTrue();
});

test('kustomizeHandlesMultiDocPatches is false when the build fails', function (): void {
    Process::fake(['kubectl kustomize *' => Process::result(output: '', exitCode: 1)]);

    expect(kustomizeProbe()->handles('kubectl kustomize'))->toBeFalse();
});

test('kustomizeHandlesMultiDocPatches is false when the build succeeds but produces no output', function (): void {
    Process::fake(['kubectl kustomize *' => Process::result(output: '', exitCode: 0)]);

    expect(kustomizeProbe()->handles('kubectl kustomize'))->toBeFalse();
});
