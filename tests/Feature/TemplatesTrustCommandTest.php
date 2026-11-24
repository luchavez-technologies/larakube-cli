<?php

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
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
function templatesTrustWithTempHome(callable $callback): void
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

test('templates:trust lists modified files and trusts them when --force is passed', function (): void {
    templatesTrustWithTempHome(function (TemplateStore $store, string $home): void {
        $targetFile = "{$home}/.larakube/templates/openbao/default/1.0.0/views/k8s/openbao/ingress.blade.php";
        file_put_contents($targetFile, file_get_contents($targetFile)."\n# customized ingress\n");

        $exit = Artisan::call('templates:trust', ['app' => 'openbao', '--force' => true]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('ingress.blade.php')
            ->and($output)->toContain('Trusted modified templates for openbao/default')
            ->and($store->installed()['openbao/default']['trusted_hash'])->not->toBeNull()
            ->and($store->modified())->toBe(['openbao/default'])
            ->and($store->refused())->toBeEmpty();
    });
});

test('templates:trust fails via MissingFlagException when non-interactive without --force', function (): void {
    templatesTrustWithTempHome(function (TemplateStore $store, string $home): void {
        $targetFile = "{$home}/.larakube/templates/openbao/default/1.0.0/views/k8s/openbao/ingress.blade.php";
        file_put_contents($targetFile, file_get_contents($targetFile)."\n# customized ingress\n");

        expect(fn () => Artisan::call('templates:trust', ['app' => 'openbao']))
            ->toThrow(MissingFlagException::class, 'Missing required --force')
            ->and($store->installed()['openbao/default']['trusted_hash'])->toBeNull();
    });
});
