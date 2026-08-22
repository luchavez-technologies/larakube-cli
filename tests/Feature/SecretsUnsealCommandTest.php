<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('secrets:unseal is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('secrets:unseal');
});

test('secrets:unseal fails when OpenBao is not deployed', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('secrets:unseal local')
        ->assertExitCode(1)
        ->expectsOutputToContain('not deployed');
});

test('secrets:unseal fails when OpenBao was never initialized', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([DynamicNoBodyRequest::class => MockResponse::make(['initialized' => false])]);

    $this->artisan('secrets:unseal local')
        ->assertExitCode(1)
        ->expectsOutputToContain('never been initialized');
});

test('secrets:unseal unseals a sealed OpenBao', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicRequest::class => MockResponse::make(['sealed' => false]),
        DynamicNoBodyRequest::class => openBaoFake([
            '*seal-status' => ['sealed' => true],
        ], default: ['initialized' => true]),
    ]);

    $this->artisan('secrets:unseal local')
        ->assertExitCode(0)
        ->expectsOutputToContain('is unsealed');
});

test('secrets:unseal is a no-op when already unsealed', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*seal-status' => ['sealed' => false],
        ], default: ['initialized' => true]),
    ]);

    $this->artisan('secrets:unseal local')->assertExitCode(0);

    Saloon::assertNotSent(fn ($request) => str_contains($request->resolveEndpoint(), '/sys/unseal'));
});
