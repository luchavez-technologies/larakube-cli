<?php

use App\Http\Integrations\LaraKubeCloud\LaraKubeCloudConnector;
use App\Support\Templates\CloudEndpoint;
use App\Support\Templates\TrustedKeys;

/**
 * @param  callable(): void  $callback
 */
function templateTrustedKeysWithCleanState(callable $callback): void
{
    $originalVersion = config('app.version');
    $originalTemplatesConfig = config('templates');
    $originalEnvUrl = getenv('LARAKUBE_CLOUD_URL');
    $originalServerUrl = $_SERVER['LARAKUBE_CLOUD_URL'] ?? null;
    $originalEnvSuperUrl = $_ENV['LARAKUBE_CLOUD_URL'] ?? null;

    putenv('LARAKUBE_CLOUD_URL');
    unset($_SERVER['LARAKUBE_CLOUD_URL'], $_ENV['LARAKUBE_CLOUD_URL']);
    config(['templates.cloud_url' => null]);

    try {
        $callback();
    } finally {
        TrustedKeys::resolveRunningInPharUsing(null);
        TrustedKeys::resolveStableReleaseUsing(null);
        config([
            'app.version' => $originalVersion,
            'templates' => $originalTemplatesConfig,
        ]);

        if ($originalEnvUrl === false) {
            putenv('LARAKUBE_CLOUD_URL');
        } else {
            putenv("LARAKUBE_CLOUD_URL={$originalEnvUrl}");
        }

        if ($originalServerUrl === null) {
            unset($_SERVER['LARAKUBE_CLOUD_URL']);
        } else {
            $_SERVER['LARAKUBE_CLOUD_URL'] = $originalServerUrl;
        }

        if ($originalEnvSuperUrl === null) {
            unset($_ENV['LARAKUBE_CLOUD_URL']);
        } else {
            $_ENV['LARAKUBE_CLOUD_URL'] = $originalEnvSuperUrl;
        }
    }
}

test('stable release trusts production keys only and never staging or dev keys in phar', function (): void {
    templateTrustedKeysWithCleanState(function (): void {
        TrustedKeys::resolveRunningInPharUsing(static fn (): bool => true);
        TrustedKeys::resolveStableReleaseUsing(static fn (): bool => true);
        config(['templates.dev_key' => 'dev-local:SrlBfws1G209Ospdz1zcCHG1TP4bnD06O07X5dpnioU=']);

        $keys = TrustedKeys::all();

        expect($keys)->toBe([
            'production-2026-11' => 'g7nQmcMiT3Gas/+VWnJR2PU6nrU5gtPYGJUUm5VG4JE=',
        ])
            ->and($keys)->not->toHaveKey('staging-2026-11')
            ->and($keys)->not->toHaveKey('dev-local');
    });
});

test('non-stable release trusts both production and staging keys', function (): void {
    templateTrustedKeysWithCleanState(function (): void {
        TrustedKeys::resolveRunningInPharUsing(static fn (): bool => true);
        TrustedKeys::resolveStableReleaseUsing(static fn (): bool => false);

        $keys = TrustedKeys::all();

        expect($keys)->toBe([
            'production-2026-11' => 'g7nQmcMiT3Gas/+VWnJR2PU6nrU5gtPYGJUUm5VG4JE=',
            'staging-2026-11' => 'KWbj/BrMS5Uk3heeUeZxpRsk5O5dc6xRkKtOYmk382g=',
        ]);
    });
});

test('isStableRelease detects semver tags versus canary shas and git describe strings', function (): void {
    templateTrustedKeysWithCleanState(function (): void {
        TrustedKeys::resolveStableReleaseUsing(null);

        config(['app.version' => 'v0.34.0']);
        expect(TrustedKeys::isStableRelease())->toBeTrue();

        config(['app.version' => '1.2.3']);
        expect(TrustedKeys::isStableRelease())->toBeTrue();

        config(['app.version' => 'v0.34.0-4-g9a8b7c6']);
        expect(TrustedKeys::isStableRelease())->toBeFalse();

        config(['app.version' => '9a8b7c6d5e4f3a2b1c0d']);
        expect(TrustedKeys::isStableRelease())->toBeFalse();
    });
});

test('cloud endpoint resolves url per channel and honours LARAKUBE_CLOUD_URL override', function (): void {
    templateTrustedKeysWithCleanState(function (): void {
        TrustedKeys::resolveStableReleaseUsing(static fn (): bool => true);
        expect(CloudEndpoint::url())->toBe('https://cloud.larakube.app')
            ->and((new LaraKubeCloudConnector)->resolveBaseUrl())->toBe('https://cloud.larakube.app');

        TrustedKeys::resolveStableReleaseUsing(static fn (): bool => false);
        expect(CloudEndpoint::url())->toBe('https://cloud-stg.larakube.app')
            ->and((new LaraKubeCloudConnector)->resolveBaseUrl())->toBe('https://cloud-stg.larakube.app');

        putenv('LARAKUBE_CLOUD_URL=https://custom-cloud.larakube.test/');
        TrustedKeys::resolveStableReleaseUsing(static fn (): bool => true);

        expect(CloudEndpoint::url())->toBe('https://custom-cloud.larakube.test')
            ->and((new LaraKubeCloudConnector)->resolveBaseUrl())->toBe('https://custom-cloud.larakube.test');
    });
});
