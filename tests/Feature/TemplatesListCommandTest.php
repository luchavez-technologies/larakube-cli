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
 * @param  callable(TemplateStore, string): void  $callback
 */
function templatesListWithTempHome(callable $callback): void
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
        $callback($store, $tempHome->path());
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

test('templates:list reports ok, refused, and modified statuses in table and json modes', function (): void {
    templatesListWithTempHome(function (TemplateStore $store, string $home): void {
        // 1. Clean install => ok
        $exitOk = Artisan::call('templates:list', ['--json' => true]);
        expect($exitOk)->toBe(0);
        $payloadOk = json_decode(Artisan::output(), true);
        expect($payloadOk['apps'])->toHaveCount(1)
            ->and($payloadOk['apps'][0]['app'])->toBe('openbao')
            ->and($payloadOk['apps'][0]['engine'])->toBe('default')
            ->and($payloadOk['apps'][0]['version'])->toBe('1.0.0')
            ->and($payloadOk['apps'][0]['status'])->toBe('ok');

        // 2. Untrusted modification => refused
        $targetFile = "{$home}/.larakube/templates/openbao/default/1.0.0/views/k8s/openbao/openbao.blade.php";
        file_put_contents($targetFile, file_get_contents($targetFile)."\n# edited\n");

        $exitRefused = Artisan::call('templates:list', ['--json' => true]);
        expect($exitRefused)->toBe(0);
        $payloadRefused = json_decode(Artisan::output(), true);
        expect($payloadRefused['apps'][0]['status'])->toBe('refused');

        $humanRefusedExit = Artisan::call('templates:list');
        $humanRefusedOutput = Artisan::output();
        expect($humanRefusedExit)->toBe(0)
            ->and($humanRefusedOutput)->toContain('refused');

        // 3. Trusted modification => modified
        $store->trust(ClusterTool::OPENBAO);

        $exitModified = Artisan::call('templates:list', ['--json' => true]);
        expect($exitModified)->toBe(0);
        $payloadModified = json_decode(Artisan::output(), true);
        expect($payloadModified['apps'][0]['status'])->toBe('modified');

        $humanModifiedExit = Artisan::call('templates:list');
        $humanModifiedOutput = Artisan::output();
        expect($humanModifiedExit)->toBe(0)
            ->and($humanModifiedOutput)->toContain('modified');
    });
});
