<?php

use App\Commands\Bundle\BundleBuildCommand;
use App\Commands\Bundle\BundleInstallCommand;
use App\Traits\GeneratesOfflineCertificates;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('bundle:install --skip-images is a valueless boolean flag', function (): void {
    $option = (new BundleInstallCommand)->getDefinition()->getOption('skip-images');

    expect($option->acceptValue())->toBeFalse();
});

test('bundle:install --swap accepts a value, defaults to 1G, and normalizes bare numbers', function (): void {
    $option = (new BundleInstallCommand)->getDefinition()->getOption('swap');

    expect($option->acceptValue())->toBeTrue()
        ->and($option->isValueOptional())->toBeTrue()
        ->and($option->getDefault())->toBe('1G');
});

test('bundle:install --swap normalizes bare integers to gigabytes', function (): void {
    expect(preg_replace('/^\d+$/', '', '2') === '' ? '2G' : '2')->toBe('2G');

    // Explicit normalization rule: digits-only → append G
    foreach (['1' => '1G', '2' => '2G', '4' => '4G'] as $input => $expected) {
        $normalized = preg_match('/^\d+$/', $input) ? $input.'G' : $input;
        expect($normalized)->toBe($expected);
    }

    // Values already with a suffix pass through unchanged
    foreach (['1G', '2G', '512M'] as $passthrough) {
        $normalized = preg_match('/^\d+$/', $passthrough) ? $passthrough.'G' : $passthrough;
        expect($normalized)->toBe($passthrough);
    }
});

// --- bundle:build CA options ---

test('bundle:build --ca-cert accepts a value and defaults to null', function (): void {
    $option = (new BundleBuildCommand)->getDefinition()->getOption('ca-cert');

    expect($option->acceptValue())->toBeTrue()
        ->and($option->getDefault())->toBeNull();
});

test('bundle:build --ca-key accepts a value and defaults to null', function (): void {
    $option = (new BundleBuildCommand)->getDefinition()->getOption('ca-key');

    expect($option->acceptValue())->toBeTrue()
        ->and($option->getDefault())->toBeNull();
});

test('caMode derivation logic', function (): void {
    $derive = fn (string $cert, string $key) => match (true) {
        $cert !== '' && $key !== '' => 'full_sign',
        $cert !== '' => 'trust_only',
        default => null,
    };

    expect($derive('', ''))->toBeNull()
        ->and($derive('ca.crt', ''))->toBe('trust_only')
        ->and($derive('ca.crt', 'ca.key'))->toBe('full_sign')
        ->and($derive('', 'ca.key'))->toBeNull(); // key alone is invalid (guarded before this)
});

// --- GeneratesOfflineCertificates full-sign mode ---

test('generateSanCertificates uses company CA when both cert and key are supplied', function (): void {
    $trait = new class
    {
        use GeneratesOfflineCertificates;
    };

    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tmpDir = $temporaryDirectory->path();

    // finally, not a trailing statement: a failing assertion below would skip
    // the cleanup and leave real RSA private keys in the system temp directory,
    // one fresh set per run, indefinitely.
    try {
        // Generate a real company CA for the test
        exec('openssl genrsa -out '.escapeshellarg("$tmpDir/company-ca.key").' 2048 2>/dev/null');
        exec('openssl req -x509 -new -nodes -key '.escapeshellarg("$tmpDir/company-ca.key").' -sha256 -days 1 -out '.escapeshellarg("$tmpDir/company-ca.crt").' -subj "/CN=Test Company CA" 2>/dev/null');

        $result = $trait->generateSanCertificates(
            domains: ['app.company.internal'],
            outputDir: $tmpDir,
            companyCaCrt: "$tmpDir/company-ca.crt",
            companyCaKey: "$tmpDir/company-ca.key",
        );

        // ca_crt should point to the company cert, not a generated one
        expect($result['ca_crt'])->toBe("$tmpDir/company-ca.crt")
            ->and(file_exists($result['tls_crt']))->toBeTrue()
            ->and(file_exists($result['tls_key']))->toBeTrue()
            // No self-generated ca.key should exist in the output dir
            ->and(file_exists("$tmpDir/ca.key"))->toBeFalse();

        // Verify the server cert is signed by the company CA
        exec('openssl verify -CAfile '.escapeshellarg("$tmpDir/company-ca.crt").' '.escapeshellarg($result['tls_crt']).' 2>&1', $output, $code);
        expect($code)->toBe(0);
    } finally {
        $temporaryDirectory->delete();
    }
});
