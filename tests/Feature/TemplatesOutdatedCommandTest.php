<?php

use App\Enums\ClusterTool;
use App\Http\Integrations\LaraKubeCloud\Requests\LatestRelease;
use App\Http\Integrations\LaraKubeCloud\Requests\SigningKeys;
use App\Services\TemplateStore;
use App\Support\Templates\TrustedKeys;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * @param  callable(TemplateStore): void  $callback
 */
function templatesOutdatedWithTempHome(callable $callback): void
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
        $callback($store);
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

test('templates:outdated reports newer security release in table and json modes', function (): void {
    templatesOutdatedWithTempHome(function (): void {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/templates/openbao-1.0.0-signed.json')), true);
        $newerManifest = $fixture['manifest'];
        $newerManifest['version'] = '1.1.0';
        $newerManifest['security'] = true;

        MockClient::global([
            LatestRelease::class => MockResponse::make([
                'manifest' => $newerManifest,
                'signature' => $fixture['signature'],
                'key_id' => $fixture['key_id'],
            ]),
        ]);

        $exit = Artisan::call('templates:outdated', ['--json' => true]);
        expect($exit)->toBe(0);

        $payload = json_decode(Artisan::output(), true);
        expect($payload['apps'])->toHaveCount(1)
            ->and($payload['apps'][0]['app'])->toBe('openbao')
            ->and($payload['apps'][0]['engine'])->toBe('default')
            ->and($payload['apps'][0]['installed'])->toBe('1.0.0')
            ->and($payload['apps'][0]['latest'])->toBe('1.1.0')
            ->and($payload['apps'][0]['outdated'])->toBeTrue()
            ->and($payload['apps'][0]['security'])->toBeTrue()
            ->and($payload['apps'][0]['security_fix'])->toBe('yes');

        $tableExit = Artisan::call('templates:outdated');
        $tableOutput = Artisan::output();
        expect($tableExit)->toBe(0)
            ->and($tableOutput)->toContain('openbao')
            ->and($tableOutput)->toContain('1.1.0')
            ->and($tableOutput)->toContain('yes');
    });
});

test('templates:outdated advises updating the LaraKube CLI on 404', function (): void {
    templatesOutdatedWithTempHome(function (): void {
        MockClient::global([
            LatestRelease::class => MockResponse::make(['message' => 'No release for contract 1'], 404),
        ]);

        $exit = Artisan::call('templates:outdated', ['--json' => true]);
        expect($exit)->toBe(0);

        $payload = json_decode(Artisan::output(), true);
        expect($payload['apps'][0]['latest'])->toBeNull()
            ->and($payload['apps'][0]['note'])->toContain('update the LaraKube CLI');
    });
});
