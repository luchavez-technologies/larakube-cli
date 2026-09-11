<?php

use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * ensureHttpsCompatibility() only forces the scheme on GENERATED urls. The
 * incoming request stays http:// — Traefik terminates TLS and forwards plain
 * HTTP, and Laravel ignores X-Forwarded-Proto until the proxy is trusted.
 * Laravel apps route on path so they never notice; Statamic resolves the site
 * by absolute URL, so every front-end page 404'd while /cp worked (confirmed
 * live 2026-09-09: same pod, in-process https:// = 200, http:// = 404).
 */
function trustProxiesHarness(): object
{
    return new class
    {
        use GeneratesProjectInfrastructure;

        public function run(ConfigData $config): void
        {
            $this->ensureTrustedProxies($config);
        }
    };
}

/** @return array{0: ConfigData, 1: TemporaryDirectory} */
function trustProxiesProject(string $bootstrap): array
{
    $dir = TemporaryDirectory::make();
    @mkdir($dir->path().'/bootstrap', 0755, true);
    file_put_contents($dir->path().'/bootstrap/app.php', $bootstrap);

    $config = new ConfigData;
    $config->setPath($dir->path());

    return [$config, $dir];
}

function trustProxiesSkeleton(string $body = '        //'): string
{
    return "<?php\n\nreturn Application::configure(basePath: dirname(__DIR__))\n"
        ."    ->withMiddleware(function (Middleware \$middleware): void {\n{$body}\n    })\n    ->create();\n";
}

test('the ingress proxy is trusted in a stock skeleton', function (): void {
    [$config, $dir] = trustProxiesProject(trustProxiesSkeleton());

    trustProxiesHarness()->run($config);

    $out = (string) file_get_contents($config->getPath().'/bootstrap/app.php');

    expect($out)->toContain("\$middleware->trustProxies(at: '*');")
        ->and($out)->toContain('withMiddleware');

    $dir->delete();
});

test('an existing middleware body is preserved, not replaced', function (): void {
    [$config, $dir] = trustProxiesProject(trustProxiesSkeleton("        \$middleware->encryptCookies(except: ['appearance']);"));

    trustProxiesHarness()->run($config);

    expect((string) file_get_contents($config->getPath().'/bootstrap/app.php'))
        ->toContain('encryptCookies')
        ->and((string) file_get_contents($config->getPath().'/bootstrap/app.php'))
        ->toContain('trustProxies');

    $dir->delete();
});

test('re-running never duplicates the call', function (): void {
    [$config, $dir] = trustProxiesProject(trustProxiesSkeleton());
    $harness = trustProxiesHarness();

    $harness->run($config);
    $harness->run($config);
    $harness->run($config);

    expect(substr_count((string) file_get_contents($config->getPath().'/bootstrap/app.php'), 'trustProxies'))->toBe(1);

    $dir->delete();
});

test('a closure without the void return type still matches', function (): void {
    // Skeletons differ across Laravel versions.
    $bootstrap = "<?php\n\nreturn Application::configure(basePath: dirname(__DIR__))\n"
        ."    ->withMiddleware(function (Middleware \$middleware) {\n        //\n    })\n    ->create();\n";
    [$config, $dir] = trustProxiesProject($bootstrap);

    trustProxiesHarness()->run($config);

    expect((string) file_get_contents($config->getPath().'/bootstrap/app.php'))->toContain('trustProxies');

    $dir->delete();
});

test('a project with no bootstrap/app.php is left alone', function (): void {
    // Bedrock and the polyglot scaffolders have none.
    $dir = TemporaryDirectory::make();
    $config = new ConfigData;
    $config->setPath($dir->path());

    trustProxiesHarness()->run($config);

    expect(file_exists($dir->path().'/bootstrap/app.php'))->toBeFalse();

    $dir->delete();
});
