<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('--json lists the projects of the signed-in account as ids and names', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result(output: '/usr/bin/gcloud'),
        '*projects list*' => Process::result(output: json_encode([['projectId' => 'demo-123', 'name' => 'Demo'], ['projectId' => 'x-456']])),
        '*' => Process::result(exitCode: 1),
    ]);

    expect(Artisan::call('cloud:projects', ['--provider' => 'gcp', '--json' => true]))->toBe(0);

    $result = json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true);

    expect($result['projects'])->toBe([['id' => 'demo-123', 'name' => 'Demo'], ['id' => 'x-456', 'name' => 'x-456']]);
});

test('not being signed in says how to sign in', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result(output: '/usr/bin/gcloud'),
        '*projects list*' => Process::result(exitCode: 1, errorOutput: 'not authenticated'),
    ]);

    expect(Artisan::call('cloud:projects', ['--provider' => 'gcp', '--json' => true]))->toBe(1)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true)['error'])->toContain('cloud:login --provider=gcp');
});
