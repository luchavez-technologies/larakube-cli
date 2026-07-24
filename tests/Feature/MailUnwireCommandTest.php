<?php

use Illuminate\Support\Facades\Process;

test('mail:unwire is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:unwire');
});

test('mail:unwire unsets tool mail environment variables', function () {
    Process::fake([
        '*get deployment vaultwarden*' => Process::result(output: 'vaultwarden   1/1   1   1   10d'),
        '*set env deployment/vaultwarden*' => Process::result(output: 'env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:unwire', ['--tool' => 'passwords'])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer routes mail through Stalwart');
});
