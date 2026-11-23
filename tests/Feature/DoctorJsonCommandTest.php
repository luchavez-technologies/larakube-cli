<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * `doctor --json` gives Desktop's Local page the same issues, with fixes, that
 * `doctor` prints: one JSON result on stdout.
 */
function doctorJson(): array
{
    Artisan::call('doctor', ['--json' => true]);
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

test('an unreachable cluster comes back as an issue with its fix', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(errorOutput: 'The connection to the server 127.0.0.1:6443 was refused', exitCode: 1),
        '*' => Process::result(output: 'ok'),
    ]);

    $result = doctorJson();

    expect($result['success'])->toBeTrue()
        ->and($result['environment'])->toBe('local')
        ->and(collect($result['issues'])->firstWhere('title', 'Cluster Unreachable'))->toBe([
            'title' => 'Cluster Unreachable',
            'description' => 'Cannot connect to the Kubernetes cluster.',
            'fix' => 'Ensure your cluster (Docker Desktop, OrbStack, or k3s) is running.',
        ]);
});

test('every issue has the same shape, fix included even when there is none', function (): void {
    Process::fake([
        '*cluster-info*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
        '*' => Process::result(output: 'ok'),
    ]);

    foreach (doctorJson()['issues'] as $issue) {
        expect(array_keys($issue))->toBe(['title', 'description', 'fix']);
    }
});
