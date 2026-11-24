<?php

use App\Http\Integrations\LaraKubeCloud\Requests\AppReleases;
use App\Http\Integrations\LaraKubeCloud\Requests\PublishRelease;
use App\Http\Integrations\Registry\Requests\HeadManifestRequest;
use App\Support\Templates\ReleaseVerifier;
use App\Support\Templates\TrustedKeys;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * @return array<string, string>
 */
function templatesPublishExpectedOpenbaoDigests(): array
{
    return [
        'registry-1.docker.io/v2/openbao/openbao/manifests/2.6.1' => 'sha256:5b2486ab0fb90bbc788cc345b0a08616dfb375873ee8be5df3a2fd4d378a67e0',
        'oci.external-secrets.io/v2/external-secrets/external-secrets/manifests/v2.11.0' => 'sha256:66fb710878cbf3eba4a927e35c5d75e29a202c9bab238aad64bfb23b786b962b',
        'ghcr.io/v2/stakater/reloader/manifests/v1.0.69' => 'sha256:dacc5ff21845d83ee84717261d3d8e3a0a2f6ded23b270ffef4340dc8d2f7500',
    ];
}

function templatesPublishHeadManifestFake(): Closure
{
    $digests = templatesPublishExpectedOpenbaoDigests();

    return static function (PendingRequest $pending) use ($digests): MockResponse {
        /** @var HeadManifestRequest $request */
        $request = $pending->getRequest();
        $key = "{$request->host}/v2/{$request->repo}/manifests/{$request->tag}";
        $digest = $digests[$key] ?? ('sha256:'.hash('sha256', $key));

        return MockResponse::make('', 200, [
            'Docker-Content-Digest' => $digest,
        ]);
    };
}

/**
 * @param  array<string, string|null>  $env
 * @param  callable(): void  $callback
 */
function templatesPublishWithEnv(array $env, callable $callback): void
{
    $tempHome = TemporaryDirectory::make()->deleteWhenDestroyed();
    $envKeys = [
        'HOME',
        'LARAKUBE_TEMPLATE_SIGNING_KEY',
        'LARAKUBE_TEMPLATE_SIGNING_KEY_ID',
        'LARAKUBE_RELEASE_UPLOAD_TOKEN',
        'LARAKUBE_CLOUD_URL',
    ];

    $previousGetenv = [];
    $previousServer = [];
    $previousEnv = [];

    foreach ($envKeys as $key) {
        $previousGetenv[$key] = getenv($key);
        $previousServer[$key] = $_SERVER[$key] ?? null;
        $previousEnv[$key] = $_ENV[$key] ?? null;
    }

    $originalTemplatesConfig = config('templates');

    $_SERVER['HOME'] = $tempHome->path();
    putenv('HOME='.$tempHome->path());

    foreach (['LARAKUBE_TEMPLATE_SIGNING_KEY', 'LARAKUBE_TEMPLATE_SIGNING_KEY_ID', 'LARAKUBE_RELEASE_UPLOAD_TOKEN', 'LARAKUBE_CLOUD_URL'] as $key) {
        $value = $env[$key] ?? null;
        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            putenv("{$key}={$value}");
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    try {
        $callback();
    } finally {
        TrustedKeys::resolveRunningInPharUsing(null);
        TrustedKeys::resolveStableReleaseUsing(null);
        MockClient::destroyGlobal();
        config(['templates' => $originalTemplatesConfig]);

        foreach ($envKeys as $key) {
            if ($previousGetenv[$key] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$previousGetenv[$key]}");
            }

            if ($previousServer[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previousServer[$key];
            }

            if ($previousEnv[$key] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previousEnv[$key];
            }
        }

        $tempHome->delete();
    }
}

test('templates:publish --dry-run builds a manifest for openbao whose signature verifies with the configured trusted key', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secretKeyB64 = base64_encode(sodium_crypto_sign_secretkey($keypair));
    $publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keypair));

    templatesPublishWithEnv([
        'LARAKUBE_TEMPLATE_SIGNING_KEY' => $secretKeyB64,
        'LARAKUBE_TEMPLATE_SIGNING_KEY_ID' => 'staging-ephemeral',
        'LARAKUBE_RELEASE_UPLOAD_TOKEN' => null,
    ], function () use ($publicKeyB64): void {
        config(['templates.trusted_keys' => ['staging-ephemeral' => $publicKeyB64]]);

        $mock = MockClient::global([
            HeadManifestRequest::class => templatesPublishHeadManifestFake(),
        ]);

        $exit = Artisan::call('templates:publish', [
            'apps' => ['openbao'],
            '--release-version' => '1.0.0',
            '--dry-run' => true,
            '--json' => true,
        ]);

        expect($exit)->toBe(0);

        $payload = json_decode(Artisan::output(), true);
        expect($payload)->toBeArray()
            ->and($payload['releases'])->toHaveCount(1);

        $release = $payload['releases'][0];
        $manifest = $release['manifest'];

        expect($release['app'])->toBe('openbao')
            ->and($release['version'])->toBe('1.0.0')
            ->and($release['result'])->toBe('dry-run')
            ->and($release['key_id'])->toBe('staging-ephemeral')
            ->and($manifest['app'])->toBe('openbao')
            ->and($manifest['engine'])->toBe('default')
            ->and($manifest['version'])->toBe('1.0.0')
            ->and($manifest['contract'])->toBe(1)
            ->and($manifest['security'])->toBeFalse()
            ->and(array_keys($manifest['images']))->toBe(['external-secrets', 'openbao', 'reloader'])
            ->and($manifest['images']['openbao'])->toBe('openbao/openbao:2.6.1@sha256:5b2486ab0fb90bbc788cc345b0a08616dfb375873ee8be5df3a2fd4d378a67e0')
            ->and(ReleaseVerifier::verify($manifest, $release['signature'], $publicKeyB64))->toBeTrue();

        $mock->assertNotSent(PublishRelease::class);
    });
});

test('templates:publish refuses when the secret key public key is not trusted under that key id', function (): void {
    $signingKeypair = sodium_crypto_sign_keypair();
    $otherKeypair = sodium_crypto_sign_keypair();

    $secretKeyB64 = base64_encode(sodium_crypto_sign_secretkey($signingKeypair));
    $otherPublicKeyB64 = base64_encode(sodium_crypto_sign_publickey($otherKeypair));

    templatesPublishWithEnv([
        'LARAKUBE_TEMPLATE_SIGNING_KEY' => $secretKeyB64,
        'LARAKUBE_TEMPLATE_SIGNING_KEY_ID' => 'production-2026-11',
        'LARAKUBE_RELEASE_UPLOAD_TOKEN' => 'tok_123',
    ], function () use ($otherPublicKeyB64): void {
        config([
            'templates.trusted_keys' => ['production-2026-11' => $otherPublicKeyB64],
            'templates.staging_keys' => [],
            'templates.dev_key' => null,
        ]);

        $mock = MockClient::global([
            HeadManifestRequest::class => templatesPublishHeadManifestFake(),
        ]);

        $exit = Artisan::call('templates:publish', [
            'apps' => ['openbao'],
            '--release-version' => '1.0.0',
            '--dry-run' => true,
            '--json' => true,
        ]);

        expect($exit)->not->toBe(0)
            ->and(Artisan::output())->toContain('does not match any trusted public key');

        $mock->assertNothingSent();
    });
});

test('templates:publish uploads the exact bundle and reports 201 and next-patch version from the versions endpoint', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secretKeyB64 = base64_encode(sodium_crypto_sign_secretkey($keypair));
    $publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keypair));

    templatesPublishWithEnv([
        'LARAKUBE_TEMPLATE_SIGNING_KEY' => $secretKeyB64,
        'LARAKUBE_TEMPLATE_SIGNING_KEY_ID' => 'staging-2026-11',
        'LARAKUBE_RELEASE_UPLOAD_TOKEN' => 'upload-secret-token',
    ], function () use ($publicKeyB64): void {
        config(['templates.staging_keys' => ['staging-2026-11' => $publicKeyB64]]);
        TrustedKeys::resolveStableReleaseUsing(static fn (): bool => false);

        $capturedBody = null;
        $capturedAuthHeader = null;

        MockClient::global([
            AppReleases::class => MockResponse::make([
                'versions' => ['1.0.0', '1.2.4', '1.2.1'],
            ], 200),
            HeadManifestRequest::class => templatesPublishHeadManifestFake(),
            PublishRelease::class => function (PendingRequest $pending) use (&$capturedBody, &$capturedAuthHeader): MockResponse {
                $capturedBody = $pending->body()?->all();
                $capturedAuthHeader = $pending->headers()->get('Authorization');

                return MockResponse::make([
                    'app' => 'openbao',
                    'version' => '1.2.5',
                ], 201);
            },
        ]);

        $exit = Artisan::call('templates:publish', [
            'apps' => ['openbao'],
            '--security' => true,
            '--json' => true,
        ]);

        expect($exit)->toBe(0)
            ->and($capturedAuthHeader)->toBe('Bearer upload-secret-token')
            ->and($capturedBody)->toBeArray()
            ->and($capturedBody)->toHaveKeys(['manifest', 'signature', 'key_id', 'files'])
            ->and($capturedBody['key_id'])->toBe('staging-2026-11')
            ->and($capturedBody['manifest']['app'])->toBe('openbao')
            ->and($capturedBody['manifest']['version'])->toBe('1.2.5')
            ->and($capturedBody['manifest']['security'])->toBeTrue()
            ->and(ReleaseVerifier::verify($capturedBody['manifest'], $capturedBody['signature'], $publicKeyB64))->toBeTrue()
            ->and($capturedBody['files'])->toHaveCount(10)
            ->and($capturedBody['files'][0])->toHaveKeys(['path', 'content']);

        $payload = json_decode(Artisan::output(), true);
        expect($payload['releases'][0]['version'])->toBe('1.2.5')
            ->and($payload['releases'][0]['status'])->toBe(201)
            ->and($payload['releases'][0]['result'])->toContain('201');
    });
});

test('templates:publish reports 409 when the release version already exists', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secretKeyB64 = base64_encode(sodium_crypto_sign_secretkey($keypair));
    $publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keypair));

    templatesPublishWithEnv([
        'LARAKUBE_TEMPLATE_SIGNING_KEY' => $secretKeyB64,
        'LARAKUBE_TEMPLATE_SIGNING_KEY_ID' => 'staging-2026-11',
        'LARAKUBE_RELEASE_UPLOAD_TOKEN' => 'upload-secret-token',
    ], function () use ($publicKeyB64): void {
        config(['templates.staging_keys' => ['staging-2026-11' => $publicKeyB64]]);
        TrustedKeys::resolveStableReleaseUsing(static fn (): bool => false);

        MockClient::global([
            HeadManifestRequest::class => templatesPublishHeadManifestFake(),
            PublishRelease::class => MockResponse::make([
                'message' => 'Release version 1.0.0 already exists.',
            ], 409),
        ]);

        $exit = Artisan::call('templates:publish', [
            'apps' => ['openbao'],
            '--release-version' => '1.0.0',
            '--json' => true,
        ]);

        expect($exit)->toBe(0);

        $payload = json_decode(Artisan::output(), true);
        expect($payload['releases'][0]['status'])->toBe(409)
            ->and($payload['releases'][0]['result'])->toContain('409');
    });
});

test('templates:publish fails when required environment secrets are missing', function (): void {
    templatesPublishWithEnv([
        'LARAKUBE_TEMPLATE_SIGNING_KEY' => null,
        'LARAKUBE_TEMPLATE_SIGNING_KEY_ID' => null,
        'LARAKUBE_RELEASE_UPLOAD_TOKEN' => null,
    ], function (): void {
        $exit = Artisan::call('templates:publish', [
            'apps' => ['openbao'],
            '--json' => true,
        ]);

        // Artisan::output() empties its buffer on read, so read it once.
        $output = Artisan::output();

        expect($exit)->not->toBe(0)
            ->and($output)->toContain('LARAKUBE_TEMPLATE_SIGNING_KEY')
            ->and($output)->toContain('LARAKUBE_RELEASE_UPLOAD_TOKEN');
    });
});
