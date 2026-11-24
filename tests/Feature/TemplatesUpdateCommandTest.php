<?php

use App\Enums\ClusterTool;
use App\Http\Integrations\LaraKubeCloud\Requests\LatestRelease;
use App\Http\Integrations\LaraKubeCloud\Requests\SigningKeys;
use App\Services\TemplateStore;
use App\Support\Templates\CanonicalJson;
use App\Support\Templates\TrustedKeys;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * @param  callable(TemplateStore, string, array<string, mixed>, array<string, string>): void  $callback
 */
function templatesUpdateWithTempHome(callable $callback): void
{
    $tempHome = TemporaryDirectory::make()->deleteWhenDestroyed();
    $previousServerHome = $_SERVER['HOME'] ?? null;
    $previousEnvHome = getenv('HOME');

    $_SERVER['HOME'] = $tempHome->path();
    putenv('HOME='.$tempHome->path());

    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/templates/openbao-1.0.0-signed.json')), true);
    config([
        'templates.trusted_keys' => [],
        'templates.dev_key' => "{$fixture['key_id']}:{$fixture['public_key']}",
    ]);
    TrustedKeys::resolveRunningInPharUsing(static fn (): bool => false);

    $files = [];
    foreach (glob(base_path('resources/views/k8s/openbao/*.blade.php')) ?: [] as $filePath) {
        $files[basename($filePath)] = (string) file_get_contents($filePath);
    }

    MockClient::global([
        LatestRelease::class => MockResponse::make([
            'manifest' => $fixture['manifest'],
            'signature' => $fixture['signature'],
            'key_id' => $fixture['key_id'],
            'files' => $files,
        ]),
        SigningKeys::class => MockResponse::make(['keys' => []]),
    ]);

    $store = app(TemplateStore::class);
    $store->download(ClusterTool::OPENBAO);
    MockClient::destroyGlobal();

    try {
        $callback($store, $tempHome->path(), $fixture, $files);
    } finally {
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

test('templates:update downloads a newer release and reports what changed in json mode', function (): void {
    templatesUpdateWithTempHome(function (TemplateStore $store, string $home, array $fixture, array $files): void {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keypair));
        config(['templates.trusted_keys' => ['dev-v2' => $publicKeyB64]]);

        $manifestV2 = $fixture['manifest'];
        $manifestV2['version'] = '1.2.0';
        $sigV2 = base64_encode(sodium_crypto_sign_detached(CanonicalJson::encode($manifestV2), $secretKey));

        MockClient::global([
            LatestRelease::class => MockResponse::make([
                'manifest' => $manifestV2,
                'signature' => $sigV2,
                'key_id' => 'dev-v2',
                'files' => $files,
            ]),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        $exit = Artisan::call('templates:update', ['--json' => true]);
        expect($exit)->toBe(0);

        $payload = json_decode(Artisan::output(), true);
        expect($payload['apps'])->toHaveCount(1)
            ->and($payload['apps'][0]['app'])->toBe('openbao')
            ->and($payload['apps'][0]['previous_version'])->toBe('1.0.0')
            ->and($payload['apps'][0]['version'])->toBe('1.2.0')
            ->and($payload['apps'][0]['updated'])->toBeTrue()
            ->and($store->installed()['openbao/default']['version'])->toBe('1.2.0');
    });
});

test('templates:update does not overwrite a modified and trusted release unless --force is passed', function (): void {
    templatesUpdateWithTempHome(function (TemplateStore $store, string $home, array $fixture, array $files): void {
        $targetFile = "{$home}/.larakube/templates/openbao/default/1.0.0/views/k8s/openbao/openbao.blade.php";
        file_put_contents($targetFile, file_get_contents($targetFile)."\n# custom edit\n");

        $store->trust(ClusterTool::OPENBAO);
        $store->activate();

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keypair));
        config(['templates.trusted_keys' => ['dev-v2' => $publicKeyB64]]);

        $manifestV2 = $fixture['manifest'];
        $manifestV2['version'] = '1.2.0';
        $sigV2 = base64_encode(sodium_crypto_sign_detached(CanonicalJson::encode($manifestV2), $secretKey));

        MockClient::global([
            LatestRelease::class => MockResponse::make([
                'manifest' => $manifestV2,
                'signature' => $sigV2,
                'key_id' => 'dev-v2',
                'files' => $files,
            ]),
            SigningKeys::class => MockResponse::make(['keys' => []]),
        ]);

        // Without --force: skipped
        $exit = Artisan::call('templates:update', ['app' => 'openbao', '--json' => true]);
        expect($exit)->toBe(0);

        $payload = json_decode(Artisan::output(), true);
        expect($payload['apps'][0]['skipped'])->toBeTrue()
            ->and($payload['apps'][0]['updated'])->toBeFalse()
            ->and($store->installed()['openbao/default']['version'])->toBe('1.0.0');

        // With --force: overwritten with 1.2.0
        $forceExit = Artisan::call('templates:update', ['app' => 'openbao', '--force' => true, '--json' => true]);
        expect($forceExit)->toBe(0);

        $forcePayload = json_decode(Artisan::output(), true);
        expect($forcePayload['apps'][0]['skipped'])->toBeFalse()
            ->and($forcePayload['apps'][0]['updated'])->toBeTrue()
            ->and($forcePayload['apps'][0]['version'])->toBe('1.2.0')
            ->and($store->installed()['openbao/default']['version'])->toBe('1.2.0')
            ->and($store->installed()['openbao/default']['trusted_hash'])->toBeNull();
    });
});
