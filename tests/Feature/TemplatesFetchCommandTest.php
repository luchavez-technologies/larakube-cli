<?php

use App\Http\Integrations\LaraKubeCloud\Requests\LatestRelease;
use App\Http\Integrations\LaraKubeCloud\Requests\SigningKeys;
use App\Support\Templates\TrustedKeys;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** @param callable(string): void $callback */
function templatesFetchWithTempHome(array $release, callable $callback): void
{
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $previous = [$_SERVER['HOME'] ?? null, getenv('HOME')];
    $_SERVER['HOME'] = $home->path();
    putenv('HOME='.$home->path());

    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/templates/openbao-1.0.0-signed.json')), true);
    config(['templates.trusted_keys' => [], 'templates.staging_keys' => [], 'templates.dev_key' => "{$fixture['key_id']}:{$fixture['public_key']}"]);
    TrustedKeys::resolveRunningInPharUsing(static fn (): bool => false);

    MockClient::global([
        LatestRelease::class => MockResponse::make($release),
        SigningKeys::class => MockResponse::make(['keys' => []]),
    ]);

    try {
        $callback($home->path());
    } finally {
        MockClient::destroyGlobal();
        TrustedKeys::resolveRunningInPharUsing(null);
        [$_SERVER['HOME'], $env] = $previous;
        $env === false ? putenv('HOME') : putenv("HOME={$env}");
    }
}

function templatesFetchRelease(): array
{
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/templates/openbao-1.0.0-signed.json')), true);
    $files = [];
    foreach (glob(base_path('resources/views/k8s/openbao/*.blade.php')) ?: [] as $path) {
        $files[] = ['path' => basename($path), 'content' => (string) file_get_contents($path)];
    }

    return ['manifest' => $fixture['manifest'], 'signature' => $fixture['signature'], 'key_id' => $fixture['key_id'], 'files' => $files];
}

test('templates:fetch downloads, verifies and caches an app without installing it', function (): void {
    templatesFetchWithTempHome(templatesFetchRelease(), function (string $home): void {
        expect(Artisan::call('templates:fetch', ['apps' => ['openbao'], '--json' => true]))->toBe(0);

        $output = json_decode(Artisan::output(), true);
        expect($output['apps'][0])->toMatchArray(['app' => 'openbao', 'version' => '1.0.0'])
            ->and("{$home}/.larakube/templates/openbao/default/1.0.0/views/k8s/openbao/openbao.blade.php")->toBeFile();
    });
});

test('templates:fetch refuses a release whose signature does not verify', function (): void {
    $release = templatesFetchRelease();
    $release['signature'] = base64_encode(str_repeat('x', 64));

    templatesFetchWithTempHome($release, function (string $home): void {
        expect(Artisan::call('templates:fetch', ['apps' => ['openbao'], '--json' => true]))->not->toBe(0)
            ->and("{$home}/.larakube/templates/openbao")->not->toBeDirectory();
    });
});

test('templates:fetch rejects an unknown app', function (): void {
    templatesFetchWithTempHome(templatesFetchRelease(), function (): void {
        expect(Artisan::call('templates:fetch', ['apps' => ['not-an-app'], '--json' => true]))->not->toBe(0);
    });
});
