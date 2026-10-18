<?php

use App\Services\WslBridge;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function wslBridgeUtf16(string $text): string
{
    return "\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
}

function wslBridgeListing(string ...$lines): string
{
    return wslBridgeUtf16("  NAME            STATE           VERSION\n".implode("\n", $lines)."\n");
}

afterEach(function (): void {
    WslBridge::setForceNativeWindows(null);
});

test('isNativeWindows can be forced for testing', function (): void {
    WslBridge::setForceNativeWindows(true);
    expect(WslBridge::isNativeWindows())->toBeTrue();

    WslBridge::setForceNativeWindows(false);
    expect(WslBridge::isNativeWindows())->toBeFalse();
});

test('text helper decodes UTF-16LE output from wsl.exe', function (): void {
    $bridge = new WslBridge;
    $raw = wslBridgeUtf16("Hello from WSL\n");
    expect($bridge->text($raw))->toBe("Hello from WSL\n");
});

test('runningDistros parses running WSL distros correctly', function (): void {
    Process::fake([
        '*--list*--running*' => Process::result(output: wslBridgeListing(
            '* larakube-ubuntu Running   2',
            '  Ubuntu          Running   2',
            '  docker-desktop  Stopped   2',
        )),
    ]);

    $bridge = new WslBridge;
    expect($bridge->runningDistros())->toBe(['larakube-ubuntu', 'Ubuntu']);
});

test('otherRunningDistros excludes larakube-ubuntu', function (): void {
    Process::fake([
        '*--list*--running*' => Process::result(output: wslBridgeListing(
            '* larakube-ubuntu Running   2',
            '  Ubuntu          Running   2',
            '  Debian          Running   2',
        )),
    ]);

    $bridge = new WslBridge;
    expect($bridge->otherRunningDistros())->toBe(['Ubuntu', 'Debian'])
        ->and($bridge->distroIsRunning())->toBeTrue();
});

test('distroExists checks quiet listing', function (): void {
    Process::fake([
        '*--list*--quiet*' => Process::result(output: wslBridgeUtf16("Ubuntu\nlarakube-ubuntu\ndocker-desktop\n")),
    ]);

    $bridge = new WslBridge;
    expect($bridge->distroExists('larakube-ubuntu'))->toBeTrue()
        ->and($bridge->distroExists('nonexistent'))->toBeFalse();
});

test('restartForNetworkingChange skips restart when other distros are running', function (): void {
    Process::fake([
        '*--list*--running*' => Process::result(output: wslBridgeListing(
            '* larakube-ubuntu Running   2',
            '  Ubuntu          Running   2',
        )),
        '*--shutdown*' => Process::result(),
    ]);

    $bridge = new WslBridge;
    expect($bridge->restartForNetworkingChange())->toBeFalse();
    Process::assertNotRan(fn ($p) => in_array('--shutdown', (array) $p->command, true));
});

test('restartForNetworkingChange executes shutdown when only target distro is running', function (): void {
    Process::fake([
        '*--list*--running*' => Process::result(output: wslBridgeListing(
            '* larakube-ubuntu Running   2',
        )),
        '*--shutdown*' => Process::result(),
    ]);

    $bridge = new WslBridge;
    expect($bridge->restartForNetworkingChange())->toBeTrue();
    Process::assertRan(fn ($p) => in_array('--shutdown', (array) $p->command, true));
});

test('mirroredNetworkingRequested checks .wslconfig in user profile', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$dir->path());

    try {
        $bridge = new WslBridge;
        expect($bridge->mirroredNetworkingRequested())->toBeFalse();

        file_put_contents($dir->path().'/.wslconfig', "[wsl2]\nnetworkingMode=mirrored\n");
        expect($bridge->mirroredNetworkingRequested())->toBeTrue();
    } finally {
        putenv('USERPROFILE');
    }
});

test('ensureMirroredNetworking writes mirrored networking config into .wslconfig', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$dir->path());

    try {
        $bridge = new WslBridge;
        expect($bridge->ensureMirroredNetworking())->toBeTrue();

        $content = (string) file_get_contents($dir->path().'/.wslconfig');
        expect($content)->toContain('[wsl2]')
            ->and($content)->toContain('networkingMode=mirrored');
    } finally {
        putenv('USERPROFILE');
    }
});

test('mirroredNetworkingActive checks wslinfo output', function (): void {
    Process::fake([
        '*wslinfo*--networking-mode*' => Process::result(output: "mirrored\n"),
    ]);

    $bridge = new WslBridge;
    expect($bridge->mirroredNetworkingActive())->toBeTrue();
});

test('mirroredRestartPending is true when configured but not active', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$dir->path());
    file_put_contents($dir->path().'/.wslconfig', "[wsl2]\nnetworkingMode=mirrored\n");

    Process::fake([
        '*wslinfo*--networking-mode*' => Process::result(output: "nat\n"),
    ]);

    try {
        $bridge = new WslBridge;
        expect($bridge->mirroredRestartPending())->toBeTrue();
    } finally {
        putenv('USERPROFILE');
    }
});

test('shouldDispatchToWsl filters lifecycle commands on Windows', function (): void {
    $bridge = new WslBridge(nativeWindows: true);

    // Operational commands -> dispatch into WSL
    expect($bridge->shouldDispatchToWsl(['larakube', 'up']))->toBeTrue()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'down']))->toBeTrue()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'art', 'migrate']))->toBeTrue()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'tool:init']))->toBeTrue();

    // Lifecycle commands -> stay on host
    expect($bridge->shouldDispatchToWsl(['larakube', 'setup']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'setup', '--tools=docker']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'update']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'wsl:restart']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'wsl:status']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', '--version']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', '-V']))->toBeFalse();
});

test('dispatchToWsl uses custom dispatcher when set', function (): void {
    $bridge = new WslBridge(nativeWindows: true);

    $dispatchedCommand = null;
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatchedCommand): int {
        $dispatchedCommand = $cmd;

        return 42;
    });

    $exit = $bridge->dispatchToWsl(['larakube', 'up', '--no-test']);
    expect($exit)->toBe(42)
        ->and($dispatchedCommand)->toBe(['wsl.exe', '-d', 'larakube-ubuntu', '--user', 'larakube', '--', 'larakube', 'up', '--no-test']);
});

test('dispatchToWsl always pins --user larakube, never the distro\'s own default login user', function (): void {
    // Confirmed live on real Windows hardware: without --user, wsl.exe runs as
    // whichever user the distro happens to default to (not necessarily
    // 'larakube'), and larakube-ubuntu's image only grants systemd lingering
    // to the larakube user -- any other user fails to start a systemd user
    // session at all ("Failed to start the systemd user session for '<user>'").
    $bridge = new WslBridge(nativeWindows: true, distro: 'custom-distro');

    $dispatchedCommand = null;
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatchedCommand): int {
        $dispatchedCommand = $cmd;

        return 0;
    });

    $bridge->dispatchToWsl(['larakube', 'tool:list']);

    expect($dispatchedCommand)->toContain('--user')
        ->and($dispatchedCommand[array_search('--user', $dispatchedCommand, true) + 1])->toBe('larakube');
});
