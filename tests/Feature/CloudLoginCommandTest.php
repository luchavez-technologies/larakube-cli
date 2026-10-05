<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('--json signs in to Google Cloud through gcloud without a browser and reports success', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result(output: '/usr/bin/gcloud'),
        '*auth login*' => Process::result(output: ''),
    ]);

    expect(Artisan::call('cloud:login', ['--provider' => 'gcp', '--json' => true]))->toBe(0)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true))->toBe(['success' => true, 'provider' => 'gcp']);

    Process::assertRan(fn ($process): bool => str_contains($process->command, '/usr/bin/gcloud auth login --no-launch-browser --update-adc'));
});

test('a missing provider flag stops a non-interactive run', function (): void {
    Process::fake();

    expect(Artisan::call('cloud:login', ['--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true))->toMatchArray(['success' => false]);
});

test('a missing gcloud is reported with the command that installs it', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(Artisan::call('cloud:login', ['--provider' => 'gcp', '--json' => true]))->toBe(1)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true)['error'])->toContain('larakube setup --tools=gcloud');
});

test('a sign-in gcloud did not finish fails', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result(output: '/usr/bin/gcloud'),
        '*auth login*' => Process::result(exitCode: 1),
    ]);

    expect(Artisan::call('cloud:login', ['--provider' => 'gcp', '--json' => true]))->toBe(1);
});
