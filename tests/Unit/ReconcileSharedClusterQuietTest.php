<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithTraefik;

/**
 * A holder that reports every local-targeting shared service as installed and
 * records what actually got applied, so the tests can tell "quiet" apart from
 * "skipped" — the distinction that matters here.
 */
function reconcileQuietHolder(): object
{
    return new class
    {
        use InteractsWithTraefik;

        /** @var array<int, string> */
        public array $applied = [];

        public array $spinLabels = [];

        public function run(ConfigData $config): void
        {
            $this->reconcileSharedCluster($config);
        }

        protected function refreshTraefikCerts(string $appName, ?string $tld = null, array $additionalHosts = []): void
        {
            // No openssl in a unit test.
        }

        protected function isSharedServicePresent(SharedClusterService $service): bool
        {
            return true;
        }

        protected function applySharedService(SharedClusterService $service, string $host): void
        {
            $this->applied[] = $service->value;
        }

        protected function withSpin(string $message, callable $callback): mixed
        {
            $this->spinLabels[] = $message;

            return $callback();
        }
    };
}

function reconcileQuietConfig(?AppFramework $framework): ConfigData
{
    return new ConfigData(id: 'demo', name: 'demo', path: '/tmp/demo', framework: $framework);
}

test('a static site summarises the shared cluster ingresses into one line', function (): void {
    $holder = reconcileQuietHolder();
    $holder->run(reconcileQuietConfig(AppFramework::VITE));

    $labels = implode("\n", $holder->spinLabels);

    // Mailpit and Stalwart have no relationship to a landing page — naming them
    // reads as `up` reaching into a dozen unrelated services.
    expect($labels)->not->toContain('Mailpit')
        ->and($labels)->not->toContain('Stalwart')
        ->and($labels)->toContain('shared cluster ingresses');

    // TLS still gets its own line: the project's own ingress terminates TLS.
    expect($labels)->toContain('Syncing local TLS certificates...');
});

test('quiet does not mean skipped — every shared service is still reconciled', function (): void {
    $static = reconcileQuietHolder();
    $static->run(reconcileQuietConfig(AppFramework::VITE));

    $laravel = reconcileQuietHolder();
    $laravel->run(reconcileQuietConfig(AppFramework::LARAVEL));

    // Skipping the work would leave these ingresses stale for anyone who only
    // ever runs static projects, so the two paths must apply the same set.
    expect($static->applied)->toBe($laravel->applied)
        ->and($static->applied)->not->toBeEmpty();
});

test('a Laravel project still gets one labelled line per service', function (): void {
    $holder = reconcileQuietHolder();
    $holder->run(reconcileQuietConfig(AppFramework::LARAVEL));

    $labels = implode("\n", $holder->spinLabels);

    expect($labels)->not->toContain('shared cluster ingresses')
        // One spin per applied service, plus the TLS line.
        ->and($holder->spinLabels)->toHaveCount(count($holder->applied) + 1);
});
