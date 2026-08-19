<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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
    Http::fake(function ($request) use (&$sealCalled) {
        if ($request->method() === 'PUT' && str_contains($request->url(), '/sys/seal')) {
            $sealCalled = true;
        }

        return Http::response([], 204);
    });

    $this->artisan('secrets:seal local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('is sealed');

    expect($sealCalled)->toBeTrue();
});
