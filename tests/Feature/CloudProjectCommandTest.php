<?php

use App\Data\GlobalConfigData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;

test('the chosen Google Cloud project is remembered', function (): void {
    expect(Artisan::call('cloud:project', ['--provider' => 'gcp', '--project' => 'demo-project-123', '--json' => true]))->toBe(0)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true))->toBe(['success' => true, 'provider' => 'gcp', 'project' => 'demo-project-123'])
        ->and(GlobalConfigData::load()->getGcpProjectId())->toBe('demo-project-123');
});

test('something that is not a project ID is refused', function (): void {
    expect(Artisan::call('cloud:project', ['--provider' => 'gcp', '--project' => 'Bad Project; rm', '--json' => true]))->toBe(1);
});

test('the project flag is required when nobody can be asked', function (): void {
    expect(Artisan::call('cloud:project', ['--provider' => 'gcp', '--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true)['error'])->toContain('--project');
});
