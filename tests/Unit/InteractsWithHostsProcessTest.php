<?php

/**
 * Tests for InteractsWithHosts' Process-backed read-only queries
 * (resolveIngressIp, dnsmasqCoversKube) and writeToEtcHosts() (the sudo cp
 * /etc/hosts step, now Process-backed too). The PowerShell RunAs elevation
 * path for the Windows hosts file (syncWindowsHostsFile()) still calls real
 * Command output methods (printWindowsHostsManualHelp()'s $this->line())
 * that a bare class here can't provide, and belongs in a real-machine smoke
 * test regardless.
 */

use App\Traits\InteractsWithHosts;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function hostsProcessHelper(): object
{
    return new class
    {
        use InteractsWithHosts;

        public function ingressIp(): string
        {
            return $this->resolveIngressIp();
        }

        public function dnsmasqCovers(?string $tld = null): bool
        {
            return $this->dnsmasqCoversKube($tld);
        }

        public function writeHosts(string $content): bool
        {
            return $this->writeToEtcHosts($content);
        }

        public function winTempDir(): ?string
        {
            return $this->windowsTempDir();
        }

        public function fullyCovers(?string $tld = null): bool
        {
            return $this->dnsmasqFullyCoversKube($tld);
        }

        public function syncFile(string $content, string $entry): bool
        {
            return $this->syncWindowsHostsFile($content, $entry);
        }

        public function queueSync(string $content, string $entry): bool
        {
            return $this->queuePendingWindowsHostsSync($content, $entry);
        }
    };
}

function hostsProcessHelperOnWsl(bool $mirrored): object
{
    return new class($mirrored)
    {
        use InteractsWithHosts;

        public function __construct(private bool $mirrored) {}

        public function fullyCovers(?string $tld = null): bool
        {
            return $this->dnsmasqFullyCoversKube($tld);
        }

        protected function isWsl(): bool
        {
            return true;
        }

        protected function isDarwin(): bool
        {
            return false;
        }

        protected function mirroredNetworkingActive(): bool
        {
            return $this->mirrored;
        }
    };
}

test('resolveIngressIp prefers the LoadBalancer IP over the node InternalIP', function (): void {
    Process::fake([
        "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => '203.0.113.10',
    ]);

    expect(hostsProcessHelper()->ingressIp())->toBe('203.0.113.10');
});

test('resolveIngressIp falls back to the node InternalIP when there is no LoadBalancer IP', function (): void {
    Process::fake([
        "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => Process::result(output: '', exitCode: 1),
        "kubectl get nodes -o jsonpath='{.items[0].status.addresses[?(@.type==\"InternalIP\")].address}'" => '172.31.5.2',
    ]);

    expect(hostsProcessHelper()->ingressIp())->toBe('172.31.5.2');
});

test('resolveIngressIp falls back to 127.0.0.1 when neither is available', function (): void {
    Process::fake([
        "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => Process::result(output: '', exitCode: 1),
        "kubectl get nodes -o jsonpath='{.items[0].status.addresses[?(@.type==\"InternalIP\")].address}'" => Process::result(output: '', exitCode: 1),
    ]);

    expect(hostsProcessHelper()->ingressIp())->toBe('127.0.0.1');
});

test('dnsmasqCoversKube is false when the platform-specific dnsmasq marker file is absent', function (): void {
    $tld = 'nonexistent-tld-'.uniqid();
    expect(hostsProcessHelper()->dnsmasqCovers($tld))->toBeFalse();
});

test('writeToEtcHosts stages content in a random tempnam path, not a hardcoded predictable one', function (): void {
    Process::fake([
        'sudo cp *' => Process::result(exitCode: 0),
    ]);

    expect(hostsProcessHelper()->writeHosts("127.0.0.1 test.kube\n"))->toBeTrue();

    Process::assertRan(function ($process) {
        // Must target /etc/hosts, and must NOT be the old fixed filename —
        // a predictable /tmp path is exactly the symlink-race this fixed.
        return str_starts_with($process->command, 'sudo cp ')
            && str_ends_with($process->command, ' /etc/hosts')
            && ! str_contains($process->command, "'".sys_get_temp_dir().'/larakube_hosts'."'");
    });
});

test('writeToEtcHosts returns false when sudo cp fails', function (): void {
    Process::fake([
        'sudo cp *' => Process::result(exitCode: 1),
    ]);

    expect(hostsProcessHelper()->writeHosts("127.0.0.1 test.kube\n"))->toBeFalse();
});

test('windowsTempDir resolves the Windows %TEMP% to a writable WSL-visible path', function (): void {
    // Staging the elevated-sync files here (a native C:\ path) rather than WSL's
    // /tmp (a \\wsl.localhost UNC path) is what lets the elevated Administrator
    // session actually read them — see syncWindowsHostsFile().
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();

    Process::fake([
        'cmd.exe *' => "C:\\Users\\jsluc\\AppData\\Local\\Temp\r\n",
        'wslpath -u *' => $dir->path()."\n",
    ]);

    expect(hostsProcessHelper()->winTempDir())->toBe($dir->path());
});

test('windowsTempDir returns null when %TEMP% cannot be resolved', function (): void {
    Process::fake([
        'cmd.exe *' => Process::result(output: '', exitCode: 1),
    ]);

    expect(hostsProcessHelper()->winTempDir())->toBeNull();
});

test('dnsmasqFullyCoversKube is false off WSL when dnsmasq itself does not cover the TLD (no NRPT check needed)', function (): void {
    $tld = 'nonexistent-tld-'.uniqid();
    expect(hostsProcessHelper()->fullyCovers($tld))->toBeFalse();
});

test('queuePendingWindowsHostsSync writes the merged content to the handoff file under home_path', function (): void {
    expect(hostsProcessHelper()->queueSync("127.0.0.1 app.kube\n", 'app'))->toBeTrue()
        ->and(file_get_contents($_SERVER['HOME'].'/.larakube/pending-windows-hosts'))->toBe("127.0.0.1 app.kube\n");
});

test('syncWindowsHostsFile queues a handoff file instead of self-elevating when dispatched from native Windows', function (): void {
    // LARAKUBE_NATIVE_DISPATCH=1 is set by WslBridge::dispatchToWsl() -- see its
    // own docblock. Confirms this path never attempts the two-hop
    // wslpath+Start-Process bridge that's confirmed live to silently drop the
    // UAC prompt (same root cause as applyWindowsNrptRule()).
    putenv('LARAKUBE_NATIVE_DISPATCH=1');
    Process::fake([]);

    try {
        expect(hostsProcessHelper()->syncFile("127.0.0.1 app.kube\n", 'app.kube'))->toBeTrue()
            ->and(file_get_contents($_SERVER['HOME'].'/.larakube/pending-windows-hosts'))->toBe("127.0.0.1 app.kube\n");

        Process::assertNothingRan();
    } finally {
        putenv('LARAKUBE_NATIVE_DISPATCH');
    }
});

test('dnsmasqFullyCoversKube on WSL always returns false since WSL uses the Windows hosts file', function (): void {
    $tld = 'test';

    Process::fake(['*' => fn () => throw new RuntimeException('should not be called')]);
    expect(hostsProcessHelperOnWsl(true)->fullyCovers($tld))->toBeFalse();
});

test('readCurrentWindowsHostsContent returns pending-windows-hosts when already queued in the same native dispatch', function (): void {
    // Regression guard: during `larakube.exe up`, ensureWindowsHostsAreSet()
    // stages the project block (`test-windows`) into pending-windows-hosts,
    // and then syncClusterServiceHosts() runs syncWindowsHosts() for
    // `larakube-shared`. Without reading pending-windows-hosts first, the
    // second call re-read /mnt/c/Windows/System32/drivers/etc/hosts and
    // overwrote the project block!
    $helper = new class
    {
        use InteractsWithHosts;

        public function readCurrent(string $fallbackPath): ?string
        {
            return $this->readCurrentWindowsHostsContent($fallbackPath);
        }

        public function queue(string $content, string $entry): bool
        {
            return $this->queuePendingWindowsHostsSync($content, $entry);
        }
    };

    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $fallbackHosts = $dir->path().'/hosts';
    file_put_contents($fallbackHosts, "127.0.0.1 localhost\n");

    putenv('LARAKUBE_NATIVE_DISPATCH=1');
    try {
        @unlink($_SERVER['HOME'].'/.larakube/pending-windows-hosts');
        expect($helper->readCurrent($fallbackHosts))->toBe("127.0.0.1 localhost\n");

        $helper->queue("127.0.0.1 localhost\n127.0.0.1 test-windows.test\n", 'test-windows');
        expect($helper->readCurrent($fallbackHosts))->toBe("127.0.0.1 localhost\n127.0.0.1 test-windows.test\n");
    } finally {
        @unlink($_SERVER['HOME'].'/.larakube/pending-windows-hosts');
        putenv('LARAKUBE_NATIVE_DISPATCH');
    }
});
