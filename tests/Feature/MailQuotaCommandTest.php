<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('mail:quota is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:quota');
});

test('mail:quota requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:quota')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:quota sets quota', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com","quotas":{"maxDiskQuota":1073741824}}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"updated":{"c":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Artisan::call('mail:quota', ['--email' => 'alice@example.com', '--quota' => '10']);

    expect($exitCode)->toBe(0);
});
