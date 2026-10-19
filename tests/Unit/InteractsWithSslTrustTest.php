<?php

use App\Traits\InteractsWithSslTrust;
use Illuminate\Support\Facades\Process;

function sslTrustOnWsl(): object
{
    return new class
    {
        use InteractsWithSslTrust;

        public function trusted(): bool
        {
            return $this->isSslTrusted();
        }

        protected function isWsl(): bool
        {
            return true;
        }
    };
}

function sslTrustOnDarwin(): object
{
    return new class
    {
        use InteractsWithSslTrust;

        public function trusted(): bool
        {
            return $this->isSslTrusted();
        }

        protected function isWsl(): bool
        {
            return false;
        }

        protected function isDarwin(): bool
        {
            return true;
        }
    };
}

// isSslTrusted() short-circuits to false whenever /.dockerenv exists on non-WSL
// platforms — on WSL2, /.dockerenv is ignored because the larakube-ubuntu rootfs
// is exported from a container build and contains /.dockerenv at /.
test('isSslTrusted is false inside a Docker container on non-WSL platforms', function (): void {
    Process::fake(['security find-certificate -c "LaraKube Local CA"' => "keychain: ...\n"]);

    expect(sslTrustOnDarwin()->trusted())->toBeFalse();
})->skip(fn () => ! file_exists('/.dockerenv'), 'Only meaningful inside a container with /.dockerenv present.');

test('isSslTrusted on WSL reflects whether certutil reports the CA as valid even if /.dockerenv exists', function (): void {
    Process::fake(['*certutil.exe*-verifystore Root "LaraKube Local CA"' => 'CertUtil: -verifystore command completed successfully.']);
    expect(sslTrustOnWsl()->trusted())->toBeTrue();

    Process::fake(['*certutil.exe*-verifystore Root "LaraKube Local CA"' => Process::result(errorOutput: 'CertUtil: -verifystore command FAILED', exitCode: 1)]);
    expect(sslTrustOnWsl()->trusted())->toBeFalse();
});

test('isSslTrusted on macOS reflects whether the CA is in the keychain', function (): void {
    Process::fake(['security find-certificate -c "LaraKube Local CA"' => "keychain: ...\n"]);
    expect(sslTrustOnDarwin()->trusted())->toBeTrue();

    Process::fake(['security find-certificate -c "LaraKube Local CA"' => Process::result(output: '', exitCode: 1)]);
    expect(sslTrustOnDarwin()->trusted())->toBeFalse();
})->skip(fn () => file_exists('/.dockerenv'), 'The /.dockerenv guard short-circuits before this is ever reached.');
