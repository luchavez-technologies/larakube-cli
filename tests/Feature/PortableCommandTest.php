<?php

use Spatie\TemporaryDirectory\TemporaryDirectory;

function portableProject(): TemporaryDirectory
{
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($temporaryDirectory->path('.larakube.json'), json_encode(['name' => 'demo']));

    return $temporaryDirectory;
}

test('box.json bundles the stubs directory into the PHAR', function (): void {
    // Regression guard: if stubs/ is dropped from box.json, `larakube portable`
    // ships broken (the stub isn't inside the binary). This bit us once when a
    // revert reset box.json.
    $box = json_decode(file_get_contents(base_path('box.json')), true);
    expect($box['directories'] ?? [])->toContain('stubs');
});

test('portable stubs exist on disk', function (): void {
    expect(file_exists(base_path('stubs/portable/larakube.sh.stub')))->toBeTrue()
        ->and(file_exists(base_path('stubs/portable/LOCAL_DEV.md.stub')))->toBeTrue();
});

test('portable command writes the wrapper script and guide', function (): void {
    $original = getcwd();
    $temporaryDirectory = portableProject();
    $tmp = $temporaryDirectory->path();
    chdir($tmp);

    try {
        $this->artisan('portable', ['--force' => true])->assertExitCode(0);

        expect(file_exists("$tmp/larakube.sh"))->toBeTrue()
            ->and(file_exists("$tmp/LOCAL_DEV.md"))->toBeTrue()
            ->and(is_executable("$tmp/larakube.sh"))->toBeTrue();

        $script = file_get_contents("$tmp/larakube.sh");
        expect($script)
            ->toContain('cmd_up()')
            ->toContain('cmd_artisan()')
            ->toContain('cmd_watch()')
            ->toContain('jq -r \'.name\' .larakube.json')
            // Build must pass host UID/GID so the development stage's set-id
            // doesn't fail with "UID and GID must be numeric".
            ->toContain('--build-arg USER_ID="$(id -u)"')
            ->toContain('--build-arg GROUP_ID="$(id -g)"')
            // Local-URL access commands (so Vite/Reverb work over their hosts).
            ->toContain('cmd_hosts()')
            ->toContain('cmd_tls()')
            ->toContain('cmd_trust()')
            ->toContain('TLSStore');

        $guide = file_get_contents("$tmp/LOCAL_DEV.md");
        expect($guide)->toContain('Local development without the LaraKube CLI');
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }
});

test('portable command --script-only writes the script but not the guide', function (): void {
    $original = getcwd();
    $temporaryDirectory = portableProject();
    $tmp = $temporaryDirectory->path();
    chdir($tmp);

    try {
        $this->artisan('portable', ['--force' => true, '--script-only' => true])->assertExitCode(0);

        expect(file_exists("$tmp/larakube.sh"))->toBeTrue()
            ->and(file_exists("$tmp/LOCAL_DEV.md"))->toBeFalse();
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }
});

test('portable command --force overwrites an existing script', function (): void {
    $original = getcwd();
    $temporaryDirectory = portableProject();
    $tmp = $temporaryDirectory->path();
    file_put_contents("$tmp/larakube.sh", "# stale\n");
    chdir($tmp);

    try {
        $this->artisan('portable', ['--force' => true])->assertExitCode(0);
        expect(file_get_contents("$tmp/larakube.sh"))
            ->not->toContain('# stale')
            ->toContain('cmd_up()');
    } finally {
        chdir($original);
        $temporaryDirectory->delete();
    }
});
