<?php

/**
 * Tests for InteractsWithTrust's Process-backed read-only checks. Every
 * sudo/certutil-elevation call (installCaToKeychain/removeCaFromKeychain's
 * system trust-store writes, configureDnsmasq's /etc writes) is intentionally
 * left as raw passthru() — the established sudo carve-out this session — and
 * belongs in a real-machine smoke test.
 */

use App\Traits\InteractsWithTrust;
use Illuminate\Support\Facades\Process;

function trustHelperOnLinux(): object
{
    return new class
    {
        use InteractsWithTrust;

        public function dnsmasqInstalled(): bool
        {
            return $this->isDnsmasqInstalled();
        }

        public function caTrusted(): bool
        {
            return $this->isCaTrusted();
        }

        public function dnsmasqConfigured(): bool
        {
            return $this->isDnsmasqConfigured();
        }

        public function ensureNrptRule(array $tlds): void
        {
            $this->ensureWindowsNrptRule($tlds);
        }

        protected function isWsl(): bool
        {
            return false;
        }

        protected function isDarwin(): bool
        {
            return false;
        }

        protected function isLinux(): bool
        {
            return true;
        }
    };
}

function trustHelperOnDarwin(): object
{
    return new class
    {
        use InteractsWithTrust;

        public function dnsmasqConfPath(): ?string
        {
            return $this->getDnsmasqConfPath();
        }

        public function dnsmasqConfigured(): bool
        {
            return $this->isDnsmasqConfigured();
        }

        protected function isWsl(): bool
        {
            return false;
        }

        protected function isDarwin(): bool
        {
            return true;
        }

        protected function isLinux(): bool
        {
            return false;
        }
    };
}

test('isDnsmasqInstalled reflects whether the dnsmasq binary is on PATH', function (): void {
    Process::fake(['which dnsmasq' => "/usr/sbin/dnsmasq\n"]);
    expect(trustHelperOnLinux()->dnsmasqInstalled())->toBeTrue();

    Process::fake(['which dnsmasq' => Process::result(output: '', exitCode: 1)]);
    expect(trustHelperOnLinux()->dnsmasqInstalled())->toBeFalse();
});

test('isCaTrusted on Linux checks for the installed cert files, no shell-out', function (): void {
    expect(trustHelperOnLinux()->caTrusted())->toBeFalse();
});

test('getDnsmasqConfPath on macOS resolves under the brew prefix', function (): void {
    Process::fake(['brew --prefix' => "/opt/homebrew\n"]);

    expect(trustHelperOnDarwin()->dnsmasqConfPath())->toBe('/opt/homebrew/etc/dnsmasq.d/larakube.conf');
});

test('isDnsmasqConfigured is false on Linux when the larakube.conf drop-in does not exist', function (): void {
    expect(trustHelperOnLinux()->dnsmasqConfigured())->toBeFalse();
});

function trustHelperOnWsl(bool $mirrored): object
{
    return new class($mirrored)
    {
        use InteractsWithTrust;

        public function __construct(private bool $mirrored) {}

        public function dnsmasqConfigured(): bool
        {
            return $this->isDnsmasqConfigured();
        }

        public function ensureNrptRule(array $tlds): void
        {
            $this->ensureWindowsNrptRule($tlds);
        }

        public function nrptCovered(): array
        {
            return $this->windowsNrptCoveredTlds();
        }

        protected function isWsl(): bool
        {
            return true;
        }

        protected function isDarwin(): bool
        {
            return false;
        }

        protected function isLinux(): bool
        {
            return true;
        }

        protected function mirroredNetworkingActive(): bool
        {
            return $this->mirrored;
        }
    };
}

test('isDnsmasqConfigured on WSL is always false because WSL uses the Windows hosts file', function (): void {
    Process::fake(['*' => fn () => throw new RuntimeException('should not be called')]);

    expect(trustHelperOnWsl(true)->dnsmasqConfigured())->toBeFalse();
    Process::assertNothingRan();
});

test('windowsNrptCoveredTlds parses a single-namespace PowerShell result (bare JSON string)', function (): void {
    Process::fake(['*Get-DnsClientNrptRule*' => '".kube"']);

    expect(trustHelperOnWsl(true)->nrptCovered())->toBe(['kube']);
});

test('windowsNrptCoveredTlds parses a multi-namespace PowerShell result (JSON array)', function (): void {
    Process::fake(['*Get-DnsClientNrptRule*' => '[".kube",".test"]']);

    expect(trustHelperOnWsl(true)->nrptCovered())->toBe(['kube', 'test']);
});

test('windowsNrptCoveredTlds is empty when PowerShell reports no rules at all', function (): void {
    Process::fake(['*Get-DnsClientNrptRule*' => '']);

    expect(trustHelperOnWsl(true)->nrptCovered())->toBe([]);
});

test('ensureWindowsNrptRule never shells out off WSL or without mirrored networking', function (): void {
    Process::fake(['*' => fn () => throw new RuntimeException('no process should run')]);

    trustHelperOnLinux()->ensureNrptRule(['kube']); // not WSL at all
    trustHelperOnWsl(false)->ensureNrptRule(['kube']); // WSL, not mirrored

    Process::assertNothingRan();
});

test('ensureWindowsNrptRule is a no-op (no elevation attempted) when every TLD is already covered', function (): void {
    Process::fake(['*Get-DnsClientNrptRule*' => '[".kube"]']);

    trustHelperOnWsl(true)->ensureNrptRule(['kube']);

    Process::assertRan(fn ($process) => str_contains($process->command, 'Get-DnsClientNrptRule'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'Start-Process'));
});
