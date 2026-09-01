<?php

use App\Traits\PublishesStaticSite;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function staticBundleGuardHolder(): object
{
    return new class
    {
        use PublishesStaticSite;

        public array $lines = [];

        public function check(string $dist): bool
        {
            return $this->assertNoLocalHostsInBundle($dist, 'production');
        }

        public function line($m = '', $style = null, $verbosity = null): void
        {
            $this->lines[] = (string) $m;
        }

        public function newLine($count = 1): void {}

        protected function laraKubeError(string $m): void
        {
            $this->lines[] = $m;
        }
    };
}

function staticBundleWith(string $js): TemporaryDirectory
{
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($dir->path('index.html'), '<!doctype html><div id=root></div>');
    file_put_contents($dir->path('app.js'), $js);

    return $dir;
}

test('a bundle carrying a local host is refused', function (): void {
    // The silent failure this exists to stop: Vite bakes env vars at build
    // time, so a missing .env.production ships the LOCAL URL to production.
    $dir = staticBundleWith('const u="https://data.test";fetch(u)');
    $holder = staticBundleGuardHolder();

    expect($holder->check($dir->path()))->toBeFalse()
        ->and(implode("\n", $holder->lines))->toContain('https://data.test');
});

test('every local TLD is caught, not just .test', function (): void {
    foreach (['https://data.kube', 'https://x.localhost', 'https://y.internal', 'https://z.local'] as $url) {
        $dir = staticBundleWith("const u=\"{$url}\"");

        expect(staticBundleGuardHolder()->check($dir->path()))->toBeFalse();
    }
});

test('a real production bundle passes', function (): void {
    $dir = staticBundleWith('const u="https://data.luchtech.dev";fetch(u)');

    expect(staticBundleGuardHolder()->check($dir->path()))->toBeTrue();
});

test('lookalike words are not false positives', function (): void {
    // "latest" ends in "test" — a naive substring match would reject this.
    $dir = staticBundleWith('fetch("https://api.example.com/latest");const local=1;');

    expect(staticBundleGuardHolder()->check($dir->path()))->toBeTrue();
});
