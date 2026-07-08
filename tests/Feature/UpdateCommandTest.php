<?php

use Illuminate\Support\Facades\Http;

test('update command detects if version is up to date', function () {
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

test('update command handles update availability and cancellation', function () {
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

test('update command fails gracefully on GitHub API failure', function () {
    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest' => Http::response([], 500),
    ]);

    $this->artisan('update')
        ->expectsOutputToContain('Failed to fetch the latest version from GitHub.')
        ->assertExitCode(1);
});

test('update --canary warns and can be cancelled without ever hitting the network', function () {
    // No Http::fake() entry for releases/tags/canary — if the command reached
    // it anyway, Http::fake()'s default (successful, empty) response would
    // mask the assertion instead of failing loudly, so also assert no request
    // was ever sent to be sure the confirm short-circuits before the fetch.
    Http::fake();

    $this->artisan('update --canary')
        ->expectsOutputToContain('Canary builds are unstable, bleeding-edge builds from the tip of main')
        ->expectsConfirmation('Update to the latest canary build now?', 'no')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('update --canary fails gracefully on GitHub API failure', function () {
    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/tags/canary' => Http::response([], 500),
    ]);

    $this->artisan('update --canary')
        ->expectsConfirmation('Update to the latest canary build now?', 'yes')
        ->expectsOutputToContain('Failed to fetch the canary release from GitHub.')
        ->assertExitCode(1);
});

test('update defers to Homebrew instead of self-replacing when the running binary lives under a Cellar', function () {
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
