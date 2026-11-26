<?php

use App\Support\Templates\CanonicalJson;
use App\Support\Templates\ReleaseVerifier;
use App\Support\Templates\TrustedKeys;

/**
 * @return array{manifest: array<string, mixed>, signature: string, key_id: string, public_key: string}
 */
function templateCanonicalJsonFixture(): array
{
    $raw = file_get_contents(base_path('tests/Fixtures/templates/openbao-1.0.0-signed.json'));

    return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}

afterEach(function (): void {
    TrustedKeys::resolveRunningInPharUsing(null);
});

test('fixture manifest and signature verify with the fixture public key via ReleaseVerifier', function (): void {
    $fixture = templateCanonicalJsonFixture();

    expect(ReleaseVerifier::verify($fixture['manifest'], $fixture['signature'], $fixture['public_key']))
        ->toBeTrue();
});

test('reordering manifest keys still produces identical canonical JSON and verifies', function (): void {
    $fixture = templateCanonicalJsonFixture();
    $manifest = $fixture['manifest'];

    $reorderedFiles = array_reverse($manifest['files'], true);
    $reorderedImages = array_reverse($manifest['images'], true);

    $reorderedManifest = [
        'version' => $manifest['version'],
        'services' => [
            [
                'toggle_flag' => null,
                'shares_primary_secret' => false,
                'role' => 'primary',
                'optional' => false,
                'key' => 'app',
                'bundled_only' => false,
                'backup_paths' => ['/openbao'],
            ],
        ],
        'security' => $manifest['security'],
        'requirements' => $manifest['requirements'],
        'images' => $reorderedImages,
        'files' => $reorderedFiles,
        'engine' => $manifest['engine'],
        'contract' => $manifest['contract'],
        'app' => $manifest['app'],
    ];

    expect(CanonicalJson::encode($reorderedManifest))->toBe(CanonicalJson::encode($manifest))
        ->and(ReleaseVerifier::verify($reorderedManifest, $fixture['signature'], $fixture['public_key']))->toBeTrue();
});

test('changing one file hash in the manifest fails signature verification', function (): void {
    $fixture = templateCanonicalJsonFixture();
    $tampered = $fixture['manifest'];
    $tampered['files']['openbao.blade.php'] = 'sha256:'.str_repeat('0', 64);

    expect(ReleaseVerifier::verify($tampered, $fixture['signature'], $fixture['public_key']))
        ->toBeFalse();
});

test('CanonicalJson rejects float values anywhere in the structure', function (): void {
    expect(fn (): string => CanonicalJson::encode(['version' => 1.5]))
        ->toThrow(InvalidArgumentException::class);
});

test('TrustedKeys includes dev_key in source checkouts and ignores it when running inside a phar', function (): void {
    $fixture = templateCanonicalJsonFixture();

    // Staging keys are added on any build that is not a stable release, which a CI build is not.
    config([
        'templates.trusted_keys' => ['prod-2026' => 'cHJvZC1rZXk='],
        'templates.staging_keys' => [],
        'templates.dev_key' => "{$fixture['key_id']}:{$fixture['public_key']}",
    ]);

    TrustedKeys::resolveRunningInPharUsing(static fn (): bool => false);
    expect(TrustedKeys::all())->toHaveKeys(['prod-2026', 'dev-local'])
        ->and(TrustedKeys::withoutRevoked(['prod-2026']))->toBe(['dev-local' => $fixture['public_key']]);

    TrustedKeys::resolveRunningInPharUsing(static fn (): bool => true);
    expect(TrustedKeys::all())->toBe(['prod-2026' => 'cHJvZC1rZXk='])
        ->and(TrustedKeys::all())->not->toHaveKey('dev-local');
});
