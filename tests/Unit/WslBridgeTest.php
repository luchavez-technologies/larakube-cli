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
        ->and($bridge->shouldDispatchToWsl(['larakube', 'tool:list']))->toBeTrue();

    // Lifecycle and provisioning commands -> stay on host
    expect($bridge->shouldDispatchToWsl(['larakube', 'setup']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'setup', '--tools=docker']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'update']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'cloud:create']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'cloud:create', 'production', '--provider=do']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'tool:init', '--tool=forgejo', 'production']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'tool:add']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'tool:rebind']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'tool:remove', '--tool=forgejo', 'production']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'tls:init', 'production']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'context', 'production']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'context:import', 'lloyd.kubeconfig']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'context:remove', 'production']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'context:backup']))->toBeFalse()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'context:restore']))->toBeFalse()
        // A command outside the Cluster-Tool install/remove and kubeconfig
        // management families still dispatches to WSL, even sharing a
        // `tool:`/`context:`-style prefix — these dev-box/local-only
        // features were deliberately not part of this sweep.
        ->and($bridge->shouldDispatchToWsl(['larakube', 'tool:list']))->toBeTrue()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'companion:remove']))->toBeTrue()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'feature:remove']))->toBeTrue()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'share:remove']))->toBeTrue()
        ->and($bridge->shouldDispatchToWsl(['larakube', 'workspace:remove']))->toBeTrue()
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
        ->and($dispatchedCommand)->toBe(['wsl.exe', '-d', 'larakube-ubuntu', '--user', 'larakube', '--', 'env', 'LARAKUBE_NATIVE_DISPATCH=1', 'larakube', 'up', '--no-test']);
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

test('ensureNrptRuleForLocalTld skips entirely when the TLD is already covered', function (): void {
    Process::fake([
        '*Get-DnsClientNrptRule*' => Process::result(output: '["test"]'),
    ]);

    (new WslBridge)->ensureNrptRuleForLocalTld();

    Process::assertNotRan(fn ($p) => str_contains(implode(' ', (array) $p->command), 'Start-Process'));
});

test('ensureNrptRuleForLocalTld applies the rule natively, one hop, when not yet covered', function (): void {
    // Generalizes SetupCommand's own pre-dispatch check (previously `setup`-only)
    // to every dispatched command, since trust/up/hosts all reach the same
    // confirmed-unreliable two-hop WSL-side bridge via configureDnsmasq().
    Process::fake([
        '*Get-DnsClientNrptRule*' => Process::sequence()
            ->push(Process::result(output: '[]'))
            ->push(Process::result(output: '["test"]')),
        '*Start-Process*-Verb*RunAs*' => Process::result(),
    ]);

    (new WslBridge)->ensureNrptRuleForLocalTld();

    Process::assertRan(fn ($p) => str_contains(implode(' ', (array) $p->command), 'Start-Process')
        && str_contains(implode(' ', (array) $p->command), '-Verb'));
});

test('applyPendingWindowsHostsSync is a no-op when nothing was queued', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    Process::fake([]);

    $bridge = new WslBridge;
    $bridge->setPendingWindowsHostsPathForTesting($dir->path().'/pending-windows-hosts');

    $bridge->applyPendingWindowsHostsSync();

    Process::assertNothingRan();
});

test('applyPendingWindowsHostsSync applies the queued content via one elevated hop and clears the handoff file', function (): void {
    // Native counterpart to InteractsWithHosts::queuePendingWindowsHostsSync():
    // the WSL-side sync writes the merged content here instead of
    // self-elevating across the WSL boundary (unreliable — same root cause as
    // applyNrptRule()'s own docblock). This picks it up once dispatchToWsl()
    // returns and applies it with one direct elevation hop, same shape.
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $pendingPath = $dir->path().'/pending-windows-hosts';
    file_put_contents($pendingPath, "127.0.0.1 myapp.test\n");

    Process::fake([
        '*Start-Process*-Verb*RunAs*' => Process::result(),
    ]);

    $bridge = new WslBridge;
    $bridge->setPendingWindowsHostsPathForTesting($pendingPath);

    $bridge->applyPendingWindowsHostsSync();

    expect(file_exists($pendingPath))->toBeFalse();
    Process::assertRan(fn ($p) => str_contains(implode(' ', (array) $p->command), 'Start-Process')
        && str_contains(implode(' ', (array) $p->command), '-Verb'));
});

test('applyPendingWindowsHostsSync clears an empty handoff file without elevating', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $pendingPath = $dir->path().'/pending-windows-hosts';
    file_put_contents($pendingPath, '');

    Process::fake([]);

    $bridge = new WslBridge;
    $bridge->setPendingWindowsHostsPathForTesting($pendingPath);

    $bridge->applyPendingWindowsHostsSync();

    expect(file_exists($pendingPath))->toBeFalse();
    Process::assertNothingRan();
});

test('dispatchToWsl passes --cd when invoked from a \\\\wsl.localhost path into the target distro', function (): void {
    // Without this, project-aware commands (up, trust, ...) always land in
    // the distro's default home directory, never the project the invoking
    // terminal was actually sitting in -- confirmed as a real gap: project
    // files live under ~/projects/<name>, never directly at ~.
    $bridge = new WslBridge(nativeWindows: true);

    $dispatchedCommand = null;
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatchedCommand): int {
        $dispatchedCommand = $cmd;

        return 0;
    });

    $bridge->dispatchToWsl(['larakube', 'up'], '\\\\wsl.localhost\\larakube-ubuntu\\home\\larakube\\projects\\myapp');

    expect($dispatchedCommand)->toBe(['wsl.exe', '-d', 'larakube-ubuntu', '--user', 'larakube', '--cd', '/home/larakube/projects/myapp', '--', 'env', 'LARAKUBE_NATIVE_DISPATCH=1', 'larakube', 'up']);
});

test('dispatchToWsl passes --cd translated from a Windows drive path', function (): void {
    $bridge = new WslBridge(nativeWindows: true);

    $dispatchedCommand = null;
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatchedCommand): int {
        $dispatchedCommand = $cmd;

        return 0;
    });

    $bridge->dispatchToWsl(['larakube', 'up'], 'C:\\Users\\jsluc\\projects\\myapp');

    expect($dispatchedCommand)->toContain('--cd')
        ->and($dispatchedCommand[array_search('--cd', $dispatchedCommand, true) + 1])->toBe('/mnt/c/Users/jsluc/projects/myapp');
});

test('dispatchToWsl omits --cd when the cwd is a UNC path into a different distro', function (): void {
    $bridge = new WslBridge(nativeWindows: true);

    $dispatchedCommand = null;
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatchedCommand): int {
        $dispatchedCommand = $cmd;

        return 0;
    });

    $bridge->dispatchToWsl(['larakube', 'up'], '\\\\wsl.localhost\\Ubuntu\\home\\someone\\projects\\myapp');

    expect($dispatchedCommand)->not->toContain('--cd');
});

test('dispatchToWsl omits --cd when the cwd is not a translatable Windows path', function (): void {
    $bridge = new WslBridge(nativeWindows: true);

    $dispatchedCommand = null;
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatchedCommand): int {
        $dispatchedCommand = $cmd;

        return 0;
    });

    $bridge->dispatchToWsl(['larakube', 'up'], '/home/larakube/projects/myapp');

    expect($dispatchedCommand)->not->toContain('--cd');
});

test('windowsQuoteArgument leaves plain tokens unquoted and quotes only when needed', function (): void {
    // proc_open()'s own array-form escaping on Windows is what broke dispatch
    // (confirmed live — identical argv worked via PowerShell/Start-Process,
    // only proc_open()'s array form failed). dispatchToWsl() now builds the
    // command line itself instead; this is the quoting rule it relies on.
    $bridge = new WslBridge(nativeWindows: true);
    $quote = fn (string $arg): string => (new ReflectionMethod($bridge, 'windowsQuoteArgument'))->invoke($bridge, $arg);

    expect($quote('wsl.exe'))->toBe('wsl.exe')
        ->and($quote('larakube-ubuntu'))->toBe('larakube-ubuntu')
        ->and($quote('--user'))->toBe('--user')
        ->and($quote(''))->toBe('""')
        ->and($quote('has space'))->toBe('"has space"')
        ->and($quote('say "hi"'))->toBe('"say \\"hi\\""')
        ->and($quote('trailing\\'))->toBe('trailing\\');
});

test('larakube entrypoint bootstraps the console kernel and steps off UNC cwd before invoking WslBridge', function (): void {
    // Regression for live failure: `larakube.exe up` threw
    // "RuntimeException: A facade root has not been set." at
    // WslBridge->mirroredNetworkingActive() because `cli/larakube` resolved
    // and called WslBridge before `$kernel->bootstrap()` had registered
    // Facades and service providers.
    $entrypoint = (string) file_get_contents(base_path('larakube'));

    $bootstrapPos = strpos($entrypoint, '$kernel->bootstrap();');
    $wslBridgeMakePos = strpos($entrypoint, '$app->make(\App\Services\WslBridge::class)');

    expect($bootstrapPos)->toBeInt()
        ->and($wslBridgeMakePos)->toBeInt()
        ->and($bootstrapPos)->toBeLessThan($wslBridgeMakePos)
        ->and($entrypoint)->toContain('$wslBridge->dispatchToWsl($_SERVER[\'argv\'] ?? [], $invokingCwd)');
});

test('ensureWslBinarySynced is a no-op when no cached linux binary exists on Windows', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    Process::fake([]);

    $bridge = new WslBridge(nativeWindows: true);
    $bridge->setCachedLinuxBinaryPathForTesting($dir->path().'/nonexistent-larakube-linux-x64');

    $bridge->ensureWslBinarySynced();

    Process::assertNothingRan();
});

test('ensureWslBinarySynced skips wsl.exe sync when UNC target already matches size and mtime', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $cached = $dir->path().'/larakube-linux-x64';
    $uncTarget = $dir->path().'/wsl-usr-local-bin-larakube';

    file_put_contents($cached, 'linux-binary-payload');
    file_put_contents($uncTarget, 'linux-binary-payload');
    $mtime = time() - 60;
    touch($cached, $mtime);
    touch($uncTarget, $mtime);

    Process::fake([]);

    $bridge = new WslBridge(nativeWindows: true);
    $bridge->setCachedLinuxBinaryPathForTesting($cached);
    $bridge->setWslBinaryUncPathForTesting($uncTarget);

    $bridge->ensureWslBinarySynced();

    Process::assertNothingRan();
});

test('ensureWslBinarySynced syncs cached linux binary into WSL when UNC target differs', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $cached = $dir->path().'/larakube-linux-x64';
    $uncTarget = $dir->path().'/wsl-usr-local-bin-larakube';

    file_put_contents($cached, 'new-linux-binary-payload');
    file_put_contents($uncTarget, 'old-payload');

    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $bridge = new WslBridge(nativeWindows: true);
    $bridge->setCachedLinuxBinaryPathForTesting($cached);
    $bridge->setWslBinaryUncPathForTesting($uncTarget);

    $bridge->ensureWslBinarySynced();

    Process::assertRan(fn ($p) => in_array('root', (array) $p->command, true)
        && in_array('/usr/local/bin/larakube', (array) $p->command, true));
});
