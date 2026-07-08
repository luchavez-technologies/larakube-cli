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

test('isDnsmasqInstalled reflects whether the dnsmasq binary is on PATH', function () {
    Process::fake(['which dnsmasq' => "/usr/sbin/dnsmasq\n"]);
    expect(trustHelperOnLinux()->dnsmasqInstalled())->toBeTrue();

    Process::fake(['which dnsmasq' => Process::result(output: '', exitCode: 1)]);
    expect(trustHelperOnLinux()->dnsmasqInstalled())->toBeFalse();
});

test('isCaTrusted on Linux checks for the installed cert files, no shell-out', function () {
    expect(trustHelperOnLinux()->caTrusted())->toBeFalse();
});

test('getDnsmasqConfPath on macOS resolves under the brew prefix', function () {
    Process::fake(['brew --prefix' => "/opt/homebrew\n"]);

    expect(trustHelperOnDarwin()->dnsmasqConfPath())->toBe('/opt/homebrew/etc/dnsmasq.d/larakube.conf');
});

test('isDnsmasqConfigured is false on Linux when the larakube.conf drop-in does not exist', function () {
    expect(trustHelperOnLinux()->dnsmasqConfigured())->toBeFalse();
});
