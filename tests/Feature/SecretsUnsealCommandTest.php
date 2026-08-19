<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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

    Http::fake(['localhost:*' => Http::response(['initialized' => false], 200)]);

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

    Http::fake(function ($request) {
        $body = $request->data();

        if (isset($body['key'])) {
            return Http::response(['sealed' => false], 200);
        }

        if (str_contains($request->url(), 'seal-status')) {
            return Http::response(['sealed' => true], 200);
        }

        return Http::response(['initialized' => true], 200);
    });

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

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'seal-status')) {
            return Http::response(['sealed' => false], 200);
        }

        return Http::response(['initialized' => true], 200);
    });

    $this->artisan('secrets:unseal local')->assertExitCode(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sys/unseal'));
});
