<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('update --canary --yes streams the binary to disk and moves it into place', function (): void {
    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/tags/canary' => Http::response(['tag_name' => 'canary']),
        'github.com/luchavez-technologies/larakube-cli/releases/download/canary/*' => Http::response(str_repeat('x', 4096)),
    ]);
    Process::fake(['*' => Process::result()]);

    $this->artisan('update --canary --yes')
        ->expectsOutputToContain('LaraKube updated successfully to canary')
        ->assertExitCode(0);

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo mv ') && str_contains($process->command, '/usr/local/bin/larakube'));
});

test('update command detects if version is up to date', function (): void {
    config(['app.version' => 'v0.2.0']);

    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest' => Http::response([
            'tag_name' => 'v0.2.0',
        ], 200),
    ]);

    $this->artisan('update')
        ->expectsOutputToContain('Current version:')
        ->expectsOutputToContain('Checking for latest version...')
        ->expectsOutputToContain('You are already using the latest version!')
        ->assertExitCode(0);
});

test('update command handles update availability and cancellation', function (): void {
    config(['app.version' => 'v0.1.0']);

    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest' => Http::response([
            'tag_name' => 'v0.2.0',
        ], 200),
    ]);

    $this->artisan('update')
        ->expectsOutputToContain('A new version is available:')
        ->expectsConfirmation('Do you want to update now?', 'no')
        ->assertExitCode(0);
});

test('update command fails gracefully on release API failure', function (): void {
    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest' => Http::response([], 500),
    ]);

    $this->artisan('update')
        ->expectsOutputToContain('Failed to fetch the latest version from the GitHub release server.')
        ->assertExitCode(1);
});

test('update --canary warns and can be cancelled without ever hitting the network', function (): void {
    // No Http::fake() entry for releases/tags/canary — if the command reached
    // it anyway, Http::fake()'s default (successful, empty) response would
    // mask the assertion instead of failing loudly, so also assert no request
    // was ever sent to be sure the confirm short-circuits before the fetch.
    Http::fake();

    $this->artisan('update --canary')
        ->expectsOutputToContain('Canary builds are unstable, bleeding-edge builds from the tip of develop')
        ->expectsConfirmation('Update to the latest canary build now?', 'no')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('update --canary fails gracefully on release API failure', function (): void {
    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/tags/canary' => Http::response([], 500),
    ]);

    $this->artisan('update --canary')
        ->expectsConfirmation('Update to the latest canary build now?', 'yes')
        ->expectsOutputToContain('Failed to fetch the canary release from the GitHub release server.')
        ->assertExitCode(1);
});

test('update --canary --yes does not ask, so a caller with no terminal can update', function (): void {
    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/tags/canary' => Http::response([], 500),
    ]);

    $this->artisan('update --canary --yes')
        ->expectsOutputToContain('Failed to fetch the canary release from the GitHub release server.')
        ->assertExitCode(1);

    Http::assertSentCount(1);
});

test('update defers to Homebrew instead of self-replacing when the running binary lives under a Cellar', function (): void {
    $originalArgv0 = $_SERVER['argv'][0] ?? null;
    $_SERVER['argv'][0] = '/opt/homebrew/Cellar/larakube/0.31.0/bin/larakube';

    Http::fake();

    try {
        $this->artisan('update')
            ->expectsOutputToContain('This is a Homebrew-managed install — update via Homebrew instead:')
            ->expectsOutputToContain('brew upgrade larakube')
            ->expectsOutputToContain('brew reinstall larakube-canary')
            ->assertExitCode(0);

        Http::assertNothingSent();
    } finally {
        if ($originalArgv0 === null) {
            unset($_SERVER['argv'][0]);
        } else {
            $_SERVER['argv'][0] = $originalArgv0;
        }
    }
});
