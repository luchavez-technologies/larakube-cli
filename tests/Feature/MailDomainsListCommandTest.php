<?php

use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:domains is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:domains');
});

test('mail:domains requires installed stalwart', function (): void {
    Process::fake(['*larakube.io/tool=mail*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:domains')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:domains shows empty when no domains exist', function (): void {
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => []], 'c0'], ['x:Domain/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:domains')
        ->assertExitCode(0)
        ->expectsOutputToContain('No domains configured');
});

test('mail:domains emits machine-readable json when --json is passed', function (): void {
    Process::fake([
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [
            ['x:Domain/query', ['ids' => ['d1']], 'c0'],
            ['x:Domain/get', ['list' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Domain/get', ['list' => [['id' => 'd1', 'name' => 'example.com']], 'notFound' => []], 'c1'],
        ], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [
            ['x:Account/query', ['ids' => []], 'c2'],
            ['x:Account/get', ['list' => []], 'c3'],
        ], 'sessionState' => 'x']),
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('mail:domains', ['--json' => true]);
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"installed": true')
        ->toContain('example.com');
});
