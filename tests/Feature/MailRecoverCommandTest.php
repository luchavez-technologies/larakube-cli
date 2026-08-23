<?php

use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:recover is registered', function (): void {
    $this->artisan('list')->assertExitCode(0)->expectsOutputToContain('mail:recover');
});

test('mail:recover errors when stalwart is not installed', function (): void {
    Process::fake(['*app=mail-stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:recover', ['--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:recover re-mints the automation API key via the recovery admin', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*admin-password*' => Process::result(output: base64_encode('recovery-pass')),
        '*get secret mail-secrets*api-key*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('recovery-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*patch secret mail-secrets*' => Process::result(output: 'patched'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // automation principal already exists
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['auto1']], 'c0'], ['x:Account/get', ['list' => []], 'c1']]]),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'auto1', 'name' => 'larakube-automation']]], 'c1']]]),
        // no existing keys to destroy
        MockResponse::make(['methodResponses' => [['x:ApiKey/query', ['ids' => []], 'c0']]]),
        // mint the fresh key
        MockResponse::make(['methodResponses' => [['x:ApiKey/set', ['created' => ['k1' => ['id' => 'nk', 'secret' => 'API_FRESH']]], 'c1']]]),
    ]);

    $this->artisan('mail:recover', ['--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Automation API key re-minted');

    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret mail-secrets'));
});
