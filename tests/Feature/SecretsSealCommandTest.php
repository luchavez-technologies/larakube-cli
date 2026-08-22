<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('secrets:seal is registered', function (): void {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('secrets:seal');
});

test('secrets:seal fails when OpenBao is not deployed', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('secrets:seal local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('not deployed');
});

test('secrets:seal seals OpenBao with --force, no prompt', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $sealCalled = false;
    Saloon::fake([
        DynamicNoBodyRequest::class => function ($pendingRequest) use (&$sealCalled) {
            $request = $pendingRequest->getRequest();

            if ($request->getMethod()->value === 'PUT' && str_contains($request->resolveEndpoint(), '/sys/seal')) {
                $sealCalled = true;
            }

            return MockResponse::make([], 204);
        },
    ]);

    $this->artisan('secrets:seal local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('is sealed');

    expect($sealCalled)->toBeTrue();
});
