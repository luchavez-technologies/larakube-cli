<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\GeneratesProjectInfrastructure;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** A project dir holding exactly what `create-vite@latest` emits. */
function hardenStaticViteProject(?string $config = null): TemporaryDirectory
{
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();

    file_put_contents($dir->path('vite.config.ts'), $config ?? <<<'TS'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
})
TS);

    return $dir;
}

function hardenStaticViteHolder(): object
{
    return new class
    {
        use GeneratesProjectInfrastructure;
    };
}

function hardenStaticViteConfigData(TemporaryDirectory $dir, string $tld = 'kube'): ConfigData
{
    $config = new ConfigData(id: 'demo', name: 'demo', path: $dir->path(), framework: AppFramework::VITE);
    $config->setLocalTld($tld);

    return $config;
}

test('it injects a server block with the proxied host allowed', function (): void {
    $dir = hardenStaticViteProject();
    hardenStaticViteHolder()->hardenStaticViteConfig(hardenStaticViteConfigData($dir));

    $content = file_get_contents($dir->path('vite.config.ts'));

    // Vite 6+ rejects any non-localhost Host header outright, so without this
    // every request through Traefik 403s with "Blocked request".
    expect($content)->toContain("allowedHosts: ['demo.kube']")
        ->toContain("host: 'demo.kube'")
        ->toContain("protocol: 'wss'")
        ->toContain('clientPort: 443')
        // inotify does not reliably cross the hostPath boundary on macOS.
        ->toContain('usePolling: true')
        ->toContain('strictPort: true');
});

test('re-running after a TLD change re-aligns the host instead of duplicating the block', function (): void {
    $dir = hardenStaticViteProject();
    $holder = hardenStaticViteHolder();

    $holder->hardenStaticViteConfig(hardenStaticViteConfigData($dir, 'kube'));
    $holder->hardenStaticViteConfig(hardenStaticViteConfigData($dir, 'internal'));

    $content = file_get_contents($dir->path('vite.config.ts'));

    expect(substr_count($content, 'server: {'))->toBe(1)
        ->and($content)->toContain("allowedHosts: ['demo.internal']")
        ->and($content)->not->toContain('demo.kube');
});

test('it leaves a custom server block alone and only advises', function (): void {
    $custom = <<<'TS'
import { defineConfig } from 'vite'

export default defineConfig({
  server: {
    port: 4000,
  },
})
TS;
    $dir = hardenStaticViteProject($custom);

    // Two separate streams. laraKubeWarn() goes through Termwind, which
    // TestCase already points at a NullOutput; laraKubeLine() falls back to a
    // raw echo because this holder is not a Command, and that one escapes Pest's
    // capture entirely unless it is buffered here.
    $termwind = new Symfony\Component\Console\Output\BufferedOutput;
    Termwind\renderUsing($termwind);

    ob_start();

    try {
        hardenStaticViteHolder()->hardenStaticViteConfig(hardenStaticViteConfigData($dir));
    } finally {
        $echoed = (string) ob_get_clean();
        Termwind\renderUsing(new Symfony\Component\Console\Output\NullOutput);
    }

    // Hands off: a hand-written config is never rewritten.
    expect(file_get_contents($dir->path('vite.config.ts')))->toBe($custom)
        // ...but the user is still told exactly what their config is missing.
        ->and($termwind->fetch())->toContain('VITE ADVISORY')
        ->and($echoed)->toContain('allowedHosts')
        ->and($echoed)->toContain('usePolling: true');
});

test('it is a no-op when the project has no vite config', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();

    hardenStaticViteHolder()->hardenStaticViteConfig(hardenStaticViteConfigData($dir));

    expect(file_exists($dir->path('vite.config.ts')))->toBeFalse();
});
