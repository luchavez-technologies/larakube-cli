<?php

use Illuminate\Support\Facades\Process;

test('setup:reinstall reinstalls a Homebrew-owned tool through Homebrew', function (): void {
    Process::fake([
        'command -v k9s' => Process::result('/opt/homebrew/bin/k9s'),
        '*' => Process::result(''),
    ]);

    $this->artisan('setup:reinstall k9s')
        ->expectsOutputToContain('reinstalled at: /opt/homebrew/bin/k9s')
        ->assertExitCode(0);

    Process::assertRan('brew reinstall k9s');
});

test('setup:reinstall refuses a copy another installer owns, and says so', function (): void {
    Process::fake([
        'command -v kubectl' => Process::result('/usr/local/bin/kubectl'),
        '*' => Process::result(''),
    ]);

    $this->artisan('setup:reinstall kubectl')
        ->expectsOutputToContain('managed by another installer')
        ->assertExitCode(1);
});

test('setup:reinstall rejects an unknown tool', function (): void {
    Process::fake();

    $this->artisan('setup:reinstall nope')
        ->expectsOutputToContain("Unknown tool 'nope'")
        ->assertExitCode(1);
});
