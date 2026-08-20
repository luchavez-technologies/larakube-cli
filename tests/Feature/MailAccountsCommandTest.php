<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('mail:accounts is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:accounts');
});

test('mail:accounts shows error when stalwart not installed', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:accounts')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:accounts shows empty when no accounts exist', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":[],"queryState":"n"},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:accounts')
        ->assertExitCode(0)
        ->expectsOutputToContain('No accounts found');
});

test('mail:accounts lists accounts', function (): void {
    $callCount = 0;
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["b","c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"b","name":"admin","description":"System administrator","emailAddress":"admin@example.com","roles":{"@type":"Admin"},"quotas":{},"usedDiskQuota":486},{"id":"c","name":"alice","description":"Alice Smith","emailAddress":"alice@example.com","roles":{"@type":"User"},"quotas":{"maxDiskQuota":1073741824},"usedDiskQuota":1048576}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Artisan::call('mail:accounts');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('admin@example.com')
        ->toContain('alice@example.com');
});
