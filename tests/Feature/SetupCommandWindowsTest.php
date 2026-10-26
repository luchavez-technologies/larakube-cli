<?php

use App\Services\WslBridge;
use App\Support\Os;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

afterEach(function (): void {
    Os::setForcedFamily(null);
    WslBridge::setForceNativeWindows(null);
});

test('setup on Windows fails if WSL is not installed', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceNativeWindows(true);

    Process::fake([
        '*--status*' => Process::result(exitCode: 1),
    ]);

    $this->artisan('setup')
        ->assertExitCode(1)
        ->expectsOutputToContain('Windows Subsystem for Linux (WSL2) is not installed');
});

test('setup on Windows fails if distro is missing', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceNativeWindows(true);

    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$dir->path());
    file_put_contents($dir->path().'/.wslconfig', "[wsl2]\nnetworkingMode=mirrored\n");

    Process::fake([
        '*--status*' => Process::result(output: "Default Version: 2\n"),
        '*wslinfo*--networking-mode*' => Process::result(output: "mirrored\n"),
        '*--list*--quiet*' => Process::result(output: "Ubuntu\n"),
    ]);

    try {
        $this->artisan('setup')
            ->assertExitCode(1)
            ->expectsOutputToContain("LaraKube Linux distro ('larakube-ubuntu') is not yet installed");
    } finally {
        putenv('USERPROFILE');
    }
});

test('setup on Windows configures mirrored networking and dispatches into WSL without adding NRPT rules', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceNativeWindows(true);

    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$dir->path());
    file_put_contents($dir->path().'/.wslconfig', "[wsl2]\nnetworkingMode=mirrored\n");

    Process::fake([
        '*--status*' => Process::result(output: "Default Version: 2\n"),
        '*wslinfo*--networking-mode*' => Process::result(output: "mirrored\n"),
        '*--list*--quiet*' => Process::result(output: "larakube-ubuntu\n"),
    ]);

    $dispatched = false;
    $bridge = new WslBridge(nativeWindows: true);
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatched): int {
        $dispatched = true;

        return 0;
    });
    app()->instance(WslBridge::class, $bridge);

    try {
        $this->artisan('setup')
            ->assertExitCode(0)
            ->expectsOutputToContain('Dispatching setup into LaraKube Linux');

        expect($dispatched)->toBeTrue();
        Process::assertNotRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'Add-DnsClientNrptRule'));
    } finally {
        putenv('USERPROFILE');
    }
});

test('setup --tools=kubectl on Windows installs natively, skipping the WSL distro entirely', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceNativeWindows(true);

    $binDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LARAKUBE_BIN_DIR='.$binDir->path());

    Http::fake([
        'https://dl.k8s.io/release/stable.txt' => Http::response('v1.31.0'),
        'https://dl.k8s.io/release/v1.31.0/bin/windows/amd64/kubectl.exe' => Http::response('fake-kubectl-binary'),
    ]);

    $dispatched = false;
    $bridge = new WslBridge(nativeWindows: true);
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatched): int {
        $dispatched = true;

        return 0;
    });
    app()->instance(WslBridge::class, $bridge);

    try {
        // A single substring assertion, not two: Mockery's doWrite matcher
        // picks the first-registered expectation whose matcher fits a given
        // call, so a second expectsOutputToContain() whose text is also
        // present on the same line as the first's never gets its own match —
        // "kubectl (Kubernetes CLI)" and "installed successfully" both occur
        // on the final "✓ ... installed successfully." line here, which
        // starves the second expectation. One unambiguous assertion avoids it.
        $this->artisan('setup', ['--tools' => 'kubectl'])
            ->assertExitCode(0)
            ->expectsOutputToContain('kubectl (Kubernetes CLI) installed successfully')
            ->doesntExpectOutputToContain('Dispatching setup into LaraKube Linux');

        expect($dispatched)->toBeFalse();
        expect(file_get_contents($binDir->path().'/kubectl.exe'))->toBe('fake-kubectl-binary');
    } finally {
        putenv('LARAKUBE_BIN_DIR');
    }
});

test('setup --tools=hcloud on Windows still dispatches into WSL — no native installer exists for it yet', function (): void {
    Os::setForcedFamily('Windows');
    WslBridge::setForceNativeWindows(true);

    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('USERPROFILE='.$dir->path());
    file_put_contents($dir->path().'/.wslconfig', "[wsl2]\nnetworkingMode=mirrored\n");

    Process::fake([
        '*--status*' => Process::result(output: "Default Version: 2\n"),
        '*wslinfo*--networking-mode*' => Process::result(output: "mirrored\n"),
        '*--list*--quiet*' => Process::result(output: "larakube-ubuntu\n"),
    ]);

    $dispatched = false;
    $bridge = new WslBridge(nativeWindows: true);
    $bridge->setCustomDispatcher(function (array $cmd, ?string $cwd = null) use (&$dispatched): int {
        $dispatched = true;

        return 0;
    });
    app()->instance(WslBridge::class, $bridge);

    try {
        $this->artisan('setup', ['--tools' => 'hcloud'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dispatching setup into LaraKube Linux');

        expect($dispatched)->toBeTrue();
    } finally {
        putenv('USERPROFILE');
    }
});
