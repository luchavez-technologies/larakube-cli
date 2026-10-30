<?php

use App\Support\Os;
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

test('update --canary --yes succeeds on a real Windows host, whose php_uname(\'m\') reports uppercase AMD64', function (): void {
    // Confirmed live (2026-10-30): the architecture match was case-sensitive
    // against lowercase 'x86_64'/'amd64' literals, so every Windows update
    // failed with "Unsupported architecture: AMD64" — Windows's php_uname('m')
    // reports it uppercase, unlike Darwin/Linux's lowercase form.
    Os::setForcedFamily('Windows');
    Os::setForcedMachine('AMD64');
    $userProfile = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$userProfile->path());

    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/tags/canary' => Http::response(['tag_name' => 'canary']),
        'github.com/luchavez-technologies/larakube-cli/releases/download/canary/*' => Http::response(str_repeat('x', 4096)),
    ]);
    Process::fake(['*' => Process::result()]);

    try {
        $this->artisan('update --canary --yes')
            ->doesntExpectOutputToContain('Unsupported architecture')
            ->expectsOutputToContain('LaraKube Windows binary updated successfully to canary')
            ->assertExitCode(0);

        expect(file_exists($userProfile->path().'/.larakube/bin/larakube.exe'))->toBeTrue();
    } finally {
        Os::setForcedFamily(null);
        Os::setForcedMachine(null);
        putenv('USERPROFILE');
    }
});

test('update --canary --yes on Windows skips the WSL2 lockstep binary download when no WSL distro exists', function (): void {
    // Confirmed live (2026-10-31): a fresh Windows machine with no WSL distro
    // installed yet (e.g. a user just running "Install everything" during
    // onboarding, not creating a project) still downloaded a full second
    // larakube-linux-x64 binary on every self-update "for WSL2 lockstep
    // cache" — wasted bandwidth for a cache nothing could ever sync into.
    Os::setForcedFamily('Windows');
    Os::setForcedMachine('AMD64');
    $userProfile = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$userProfile->path());

    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/tags/canary' => Http::response(['tag_name' => 'canary']),
        'github.com/luchavez-technologies/larakube-cli/releases/download/canary/larakube-windows-x64.exe' => Http::response(str_repeat('x', 4096)),
        'github.com/luchavez-technologies/larakube-cli/releases/download/canary/larakube-linux-x64' => Http::response(str_repeat('x', 4096)),
    ]);
    // Empty `wsl.exe --list --quiet` output means no distro is installed.
    Process::fake(['*' => Process::result()]);

    try {
        $this->artisan('update --canary --yes')
            ->doesntExpectOutputToContain('WSL2 lockstep cache')
            ->doesntExpectOutputToContain('Syncing LaraKube CLI')
            ->expectsOutputToContain('LaraKube Windows binary updated successfully to canary')
            ->assertExitCode(0);

        expect(file_exists($userProfile->path().'/.larakube/bin/larakube-linux-x64'))->toBeFalse();
    } finally {
        Os::setForcedFamily(null);
        Os::setForcedMachine(null);
        putenv('USERPROFILE');
    }
});

test('update --canary --yes on Windows still downloads and syncs the Linux binary when a WSL distro exists', function (): void {
    Os::setForcedFamily('Windows');
    Os::setForcedMachine('AMD64');
    $userProfile = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$userProfile->path());

    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/tags/canary' => Http::response(['tag_name' => 'canary']),
        'github.com/luchavez-technologies/larakube-cli/releases/download/canary/larakube-windows-x64.exe' => Http::response(str_repeat('x', 4096)),
        'github.com/luchavez-technologies/larakube-cli/releases/download/canary/larakube-linux-x64' => Http::response(str_repeat('x', 4096)),
    ]);
    Process::fake([
        '*--list*' => Process::result(output: 'larakube-ubuntu'),
        '*' => Process::result(),
    ]);

    try {
        $this->artisan('update --canary --yes')
            ->expectsOutputToContain('Downloading larakube-linux-x64 for WSL2 lockstep cache')
            ->expectsOutputToContain('Syncing LaraKube CLI (canary) into Linux')
            ->assertExitCode(0);

        expect(file_exists($userProfile->path().'/.larakube/bin/larakube-linux-x64'))->toBeTrue();
    } finally {
        Os::setForcedFamily(null);
        Os::setForcedMachine(null);
        putenv('USERPROFILE');
    }
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
