<?php

use Illuminate\Support\Facades\Process;

test('mail:unwire is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:unwire');
});

test('mail:unwire unsets tool mail environment variables', function (): void {
    Process::fake([
        '*get deployment vaultwarden*' => Process::result(output: 'vaultwarden-vault-example-com   1/1   1   1   10d'),
        '*set env deployment/vaultwarden*' => Process::result(output: 'env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:unwire', ['--tool' => 'vaultwarden'])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer routes mail through Stalwart');
});

test('mail:unwire --tool=pocketbase unsets PocketBase\'s own vars, never Directus\'s', function (): void {
    // Each Data engine is its own real tool now — --tool=pocketbase can
    // only ever target the pocketbase Deployment's own env var names.
    Process::fake([
        '*get deployment pocketbase*' => Process::result(output: 'pocketbase   1/1   1   1   10d'),
        '*set env deployment/pocketbase*' => Process::result(output: 'env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('mail:unwire', ['--tool' => 'pocketbase'])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer routes mail through Stalwart');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/pocketbase')
        && str_contains($process->command, 'POCKETBASE_SMTP_HOST-'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'deployment/directus'));
});
