<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:check returns error json when no mail host is configured', function (): void {
    $exitCode = Artisan::call('mail:check', ['environment' => 'production', '--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('"installed": false')
        ->toContain('No mail host configured');
});

test('mail:check emits json report when mail host is resolved', function (): void {
    Process::fake([
        '*get deployment*jsonpath*' => Process::result(output: '1'),
        '*get secret*stalwart-relay*' => Process::result(output: ''),
        '*dig*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Artisan::call('mail:check', ['environment' => 'local', '--json' => true]);
    $output = Artisan::output();

    $data = json_decode($output, true);
    expect($data)->toBeArray()
        ->and($data['installed'])->toBeTrue()
        ->and($data['host'])->toBe('send.kube')
        ->and($data['checks'])->toBeArray();
});
