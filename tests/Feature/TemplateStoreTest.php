<?php

use App\Enums\ClusterTool;
use App\Http\Integrations\LaraKubeCloud\Requests\LatestRelease;
use App\Http\Integrations\LaraKubeCloud\Requests\SigningKeys;
use App\Services\TemplateStore;
use App\Support\Templates\CanonicalJson;
use App\Support\Templates\TrustedKeys;
use Illuminate\Support\Facades\View;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * @return array{manifest: array<string, mixed>, signature: string, key_id: string, public_key: string}
 */
function templateStoreSignedFixture(): array
{
    $raw = file_get_contents(base_path('tests/Fixtures/templates/openbao-1.0.0-signed.json'));

    return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, string>
 */
function templateStoreRealOpenBaoFiles(): array
{
    $dir = base_path('resources/views/k8s/openbao');
    $files = [];

    foreach (glob($dir.'/*.blade.php') ?: [] as $filePath) {
        $files[basename($filePath)] = (string) file_get_contents($filePath);
    }

    return $files;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function templateStoreReleasePayload(array $overrides = []): array
{
    $fixture = templateStoreSignedFixture();

    return array_replace([
        'manifest' => $fixture['manifest'],
        'signature' => $fixture['signature'],
        'key_id' => $fixture['key_id'],
        'files' => templateStoreRealOpenBaoFiles(),
    ], $overrides);
}

/**
 * @param  callable(TemplateStore, string): void  $callback
 */
function templateStoreWithTempHome(callable $callback): void
{
    $tempHome = TemporaryDirectory::make()->deleteWhenDestroyed();
    $previousServerHome = $_SERVER['HOME'] ?? null;
    $previousEnvHome = getenv('HOME');

    $_SERVER['HOME'] = $tempHome->path();
    putenv('HOME='.$tempHome->path());

    $fixture = templateStoreSignedFixture();
    config([
        'templates.trusted_keys' => [],
        'templates.dev_key' => "{$fixture['key_id']}:{$fixture['public_key']}",
    ]);
    TrustedKeys::resolveRunningInPharUsing(static fn (): bool => false);

    $store = app(TemplateStore::class);

    try {
        $callback($store, $tempHome->path());
    } finally {
        $store->resolveBundledPathUsing(null);
        TrustedKeys::resolveRunningInPharUsing(null);
        MockClient::destroyGlobal();

        if ($previousServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $previousServerHome;
        }

        if ($previousEnvHome === false) {
            putenv('HOME');
        } else {
            putenv("HOME={$previousEnvHome}");
        }

        $tempHome->delete();
    }
}

test('download happy path verifies release and writes layout and lock.json', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        $entry = $store->download(ClusterTool::OPENBAO);

        expect($entry['version'])->toBe('1.0.0')
            ->and($entry['contract'])->toBe(1)
            ->and($entry['key_id'])->toBe('dev-local')
            ->and($entry['trusted_hash'])->toBeNull();

        $releaseDir = "{$home}/.larakube/templates/openbao/default/1.0.0";
        expect("{$releaseDir}/manifest.json")->toBeFile()
            ->and("{$releaseDir}/manifest.sig")->toBeFile()
            ->and(trim((string) file_get_contents("{$releaseDir}/key_id")))->toBe('dev-local')
            ->and("{$releaseDir}/views/k8s/openbao/openbao.blade.php")->toBeFile()
            ->and("{$releaseDir}/views/k8s/openbao/eso-db-static.blade.php")->toBeFile();

        $installed = $store->installed();
        expect($installed)->toHaveKey('openbao/default')
            ->and($installed['openbao/default']['version'])->toBe('1.0.0');
    });
});

test('download refuses an untrusted key_id and writes nothing', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload(['key_id' => 'unknown-key'])),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        expect(fn (): array => $store->download(ClusterTool::OPENBAO))
            ->toThrow(RuntimeException::class, 'Untrusted signing key')
            ->and("{$home}/.larakube/templates/openbao")->not->toBeDirectory()
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('download refuses a revoked signing key and writes nothing', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make([
                'keys' => [
                    ['key_id' => 'dev-local', 'revoked' => true],
                ],
            ]),
        ]);

        expect(fn (): array => $store->download(ClusterTool::OPENBAO))
            ->toThrow(RuntimeException::class, 'revoked')
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('download refuses a dev key when running inside a phar', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        TrustedKeys::resolveRunningInPharUsing(static fn (): bool => true);

        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        expect(fn (): array => $store->download(ClusterTool::OPENBAO))
            ->toThrow(RuntimeException::class, 'Untrusted signing key')
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('download refuses a bad signature and writes nothing', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        $badSig = base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES));

        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload(['signature' => $badSig])),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        expect(fn (): array => $store->download(ClusterTool::OPENBAO))
            ->toThrow(RuntimeException::class, 'signature verification failed')
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('download refuses a file whose content does not match the signed manifest hash', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        $files = templateStoreRealOpenBaoFiles();
        $files['openbao.blade.php'] .= "\n{{-- tampered --}}";

        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload(['files' => $files])),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        expect(fn (): array => $store->download(ClusterTool::OPENBAO))
            ->toThrow(RuntimeException::class, 'hash mismatch')
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('download refuses an extra file not listed in the manifest', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        $files = templateStoreRealOpenBaoFiles();
        $files['extra.blade.php'] = 'extra';

        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload(['files' => $files])),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        expect(fn (): array => $store->download(ClusterTool::OPENBAO))
            ->toThrow(RuntimeException::class, 'do not match manifest')
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('download refuses a file path containing parent traversal', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keypair));

        config(['templates.dev_key' => "ephemeral:{$publicKeyB64}"]);

        $fixture = templateStoreSignedFixture();
        $manifest = $fixture['manifest'];
        $manifest['files']['../evil.blade.php'] = 'sha256:'.hash('sha256', 'pwn');

        $sigB64 = base64_encode(sodium_crypto_sign_detached(CanonicalJson::encode($manifest), $secretKey));
        $files = templateStoreRealOpenBaoFiles();
        $files['../evil.blade.php'] = 'pwn';

        MockClient::global([
            LatestRelease::class => MockResponse::make([
                'manifest' => $manifest,
                'signature' => $sigB64,
                'key_id' => 'ephemeral',
                'files' => $files,
            ]),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        expect(fn (): array => $store->download(ClusterTool::OPENBAO))
            ->toThrow(RuntimeException::class, 'Invalid template file path')
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('download refuses a signed manifest belonging to another app', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        expect(fn (): array => $store->download(ClusterTool::ZITADEL))
            ->toThrow(RuntimeException::class, "Manifest app 'openbao' does not match requested tool 'zitadel'")
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('download surfaces a clear plan required message on HTTP 402', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(['message' => 'Payment Required'], 402),
        ]);

        expect(fn (): array => $store->download(ClusterTool::OPENBAO))
            ->toThrow(RuntimeException::class, 'This app needs a LaraKube Cloud plan')
            ->and("{$home}/.larakube/templates/lock.json")->not->toBeFile();
    });
});

test('activate prepends a clean release, refuses a modified one, and accepts it after trust', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        $store->download(ClusterTool::OPENBAO);
        $store->activate();

        $viewsDir = "{$home}/.larakube/templates/openbao/default/1.0.0/views";
        $resolvedViewsDir = realpath($viewsDir) ?: $viewsDir;

        expect($store->modified())->toBeEmpty()
            ->and($store->refused())->toBeEmpty()
            ->and(View::getFinder()->getPaths()[0])->toBe($resolvedViewsDir);

        // Modify one template file on disk
        $targetFile = "{$viewsDir}/k8s/openbao/openbao.blade.php";
        file_put_contents($targetFile, file_get_contents($targetFile)."\n# custom comment\n");

        $store->activate();

        expect($store->refused())->toBe(['openbao/default'])
            ->and($store->modified())->toBeEmpty()
            ->and(View::getFinder()->getPaths())->not->toContain($resolvedViewsDir);

        // Explicitly trust the modification
        $trustedHash = $store->trust(ClusterTool::OPENBAO);
        expect($trustedHash)->toMatch('/^[a-f0-9]{64}$/');

        $store->activate();

        expect($store->modified())->toBe(['openbao/default'])
            ->and($store->refused())->toBeEmpty()
            ->and(View::getFinder()->getPaths()[0])->toBe($resolvedViewsDir);
    });
});

test('ensureFor downloads and activates when the bundled folder is absent', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        $mock = MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        $store->resolveBundledPathUsing(static fn (string $folder): string => "{$home}/nonexistent-bundled/{$folder}");

        $store->ensureFor('openbao');

        $mock->assertSent(LatestRelease::class);

        $viewsDir = "{$home}/.larakube/templates/openbao/default/1.0.0/views";
        $resolvedViewsDir = realpath($viewsDir) ?: $viewsDir;

        expect($store->installed())->toHaveKey('openbao/default')
            ->and(View::getFinder()->getPaths()[0])->toBe($resolvedViewsDir);
    });
});

test('ensureFor never reaches the network for an app that is not downloadable', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        $mock = MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        $store->resolveBundledPathUsing(static fn (string $folder): string => "{$home}/nonexistent-bundled/{$folder}");

        $store->ensureFor('headless-shell');
        $store->ensureFor('not-a-tool');

        $mock->assertNothingSent();
        expect($store->installed())->toBeEmpty();
    });
});

test('activate refuses a release whose file and manifest were edited together', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);
        $store->download(ClusterTool::OPENBAO);

        $release = "{$home}/.larakube/templates/openbao/default/1.0.0";
        $file = "{$release}/views/k8s/openbao/openbao.blade.php";
        file_put_contents($file, file_get_contents($file)."\n{{-- edited --}}\n");
        $manifest = json_decode((string) file_get_contents("{$release}/manifest.json"), true);
        $manifest['files']['openbao.blade.php'] = 'sha256:'.hash_file('sha256', $file);
        file_put_contents("{$release}/manifest.json", json_encode($manifest));

        $store->activate();

        expect($store->refused())->toContain('openbao/default');
    });
});

test('a refused release explains how to trust or restore it instead of a bare missing view', function (): void {
    templateStoreWithTempHome(function (TemplateStore $store, string $home): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(templateStoreReleasePayload()),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);
        $store->resolveBundledPathUsing(static fn (string $folder): string => "{$home}/nonexistent-bundled/{$folder}");
        $store->download(ClusterTool::OPENBAO);

        $file = "{$home}/.larakube/templates/openbao/default/1.0.0/views/k8s/openbao/openbao.blade.php";
        file_put_contents($file, "edited\n");
        $store->activate();

        $finder = new App\Support\Templates\TemplateViewFinder(app('files'), ["{$home}/nonexistent-bundled-root"]);

        expect(fn () => $finder->find('k8s.openbao.openbao'))->toThrow(App\Exceptions\TemplatesRefusedException::class);
    });
});
