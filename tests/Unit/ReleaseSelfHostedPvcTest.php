<?php

use App\Traits\InteractsWithPlex;
use Illuminate\Support\Facades\Process;

function plexPvcHelper(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('releaseSelfHostedPvc returns true when the plain delete finishes immediately', function (): void {
    Process::fake([
        '*delete pvc*' => Process::result(),
        '*get pvc*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(plexPvcHelper()->releaseSelfHostedPvc('kubectl', 'luchtech-local', 'app-postgres-pvc', 'postgres'))->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'scale deployment'));
});

test('releaseSelfHostedPvc scales the deployment to 0 when the PVC is still mounted, then confirms release', function (): void {
    Process::fake([
        '*delete pvc*' => Process::result(),
        '*scale deployment*' => Process::result(),
        '*get pvc*' => Process::sequence([
            Process::result(output: 'persistentvolumeclaim/app-postgres-pvc'), // still there after plain delete
            Process::result(output: '', exitCode: 1), // gone after scaling to 0
        ]),
    ]);

    expect(plexPvcHelper()->releaseSelfHostedPvc('kubectl', 'luchtech-local', 'app-postgres-pvc', 'postgres'))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'scale deployment/')
        && str_contains($process->command, 'postgres')
        && str_contains($process->command, '--replicas=0'));
});

test('releaseSelfHostedPvc returns false when the PVC is still terminating after scaling to 0', function (): void {
    Process::fake([
        '*delete pvc*' => Process::result(),
        '*scale deployment*' => Process::result(),
        '*get pvc*' => Process::result(output: 'persistentvolumeclaim/app-postgres-pvc'), // never releases
    ]);

    expect(plexPvcHelper()->releaseSelfHostedPvc('kubectl', 'luchtech-local', 'app-postgres-pvc', 'postgres'))->toBeFalse();
});
