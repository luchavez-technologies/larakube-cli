<?php

use Illuminate\Support\Facades\Process;

test('mail:unwire is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:unwire');
});

test('mail:unwire unsets tool mail environment variables', function (): void {
    Process::fake([
        '*get deployment passwords-vaultwarden*' => Process::result(output: 'passwords-vaultwarden-vaultwarden   1/1   1   1   10d'),
        '*set env deployment/passwords-vaultwarden*' => Process::result(output: 'env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:unwire', ['--tool' => 'passwords'])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer routes mail through Stalwart');
});

test('mail:unwire --tool=data unsets PocketBase\'s own vars, not Directus\'s, on a PocketBase-only install', function (): void {
    // Regression test: unwireTargets() used to call $tool->smtpEnv() with
    // NO $engine argument at all — even though it had just resolved one a
    // line above for the configuresViaConfigFile() check — so a
    // PocketBase-only install would have its Directus env var names
    // "unset" (which don't exist on that Deployment) instead of its own.
    Process::fake([
        '*get deployment data-pocketbase*' => Process::result(output: 'data-pocketbase   1/1   1   1   10d'),
        '*get deployment data-directus*' => Process::result(output: '', exitCode: 1),
        '*set env deployment/data-pocketbase*' => Process::result(output: 'env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:unwire', ['--tool' => 'data'])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer routes mail through Stalwart');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/data-pocketbase')
        && str_contains($process->command, 'POCKETBASE_SMTP_HOST-'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'deployment/data-directus'));
});
