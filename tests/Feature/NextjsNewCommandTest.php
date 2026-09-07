<?php

use App\Commands\Nextjs\NextjsNewCommand;
use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Record every command the scaffold path shells out through. Tests never get a
 * TTY, so scaffoldInNode() takes its scripted branch (Process, not passthru),
 * which is exactly what makes the container commands observable here.
 *
 * @return list<string>
 */
function nextjsScaffoldCommands(callable $run): array
{
    $recorded = [];

    Process::fake(['*' => function ($process) use (&$recorded) {
        $recorded[] = (string) $process->command;

        return Process::result(output: '');
    }]);

    $run();

    return $recorded;
}

// ── nextjs:new Command Tests ─────────────────────────────────────────────────

test('nextjs:new command is registered and has correct signature', function (): void {
    $this->artisan('nextjs:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('nextjs:new');
});

test('nextjs:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('nextjs:new')
        ->and($commands['nextjs:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Driver Compatibility Matrix — Next.js ─────────────────────────────────────

test('AppFramework NEXTJS framework value is correct', function (): void {
    expect(AppFramework::NEXTJS->value)->toBe('nextjs')
        ->and(AppFramework::NEXTJS->getLabel())->toBe('Next.js')
        ->and(AppFramework::NEXTJS->healthProbePath())->toBe('/api/health')
        ->and(AppFramework::NEXTJS->proxyCommand())->toBe('node');
});

test('Next.js CacheDriver matrix: only Redis is allowed (mandatory)', function (): void {
    // Per plan §2b: Next.js mandates Redis for distributed ISR/RSC
    $mandatory = CacheDriver::REDIS;
    $hidden = [CacheDriver::MEMCACHED, CacheDriver::DATABASE];

    expect($mandatory)->toBe(CacheDriver::REDIS)->and($hidden)->each->not->toBe(CacheDriver::REDIS);
});

test('Next.js DatabaseDriver matrix: MySQL, MariaDB, PostgreSQL are valid', function (): void {
    $supported = [
        DatabaseDriver::MYSQL,
        DatabaseDriver::MARIADB,
        DatabaseDriver::POSTGRESQL,
    ];

    $unsupported = [DatabaseDriver::SQLITE];

    expect($supported)->toHaveCount(3);

    foreach ($unsupported as $driver) {
        expect($supported)->not->toContain($driver);
    }
});

test('Next.js SearchDriver matrix: Meilisearch and Typesense are valid, database is hidden', function (): void {
    $supported = [SearchDriver::MEILISEARCH, SearchDriver::TYPESENSE];
    $hidden = [SearchDriver::DATABASE];

    expect($supported)->toHaveCount(2);

    foreach ($hidden as $driver) {
        expect($supported)->not->toContain($driver);
    }
});

// ── Next.js ConfigData Integration ───────────────────────────────────────────

test('ConfigData accepts AppFramework::NEXTJS and mandatory Redis cache', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::NEXTJS;
    $config->cacheDriver = CacheDriver::REDIS;

    expect($config->framework)->toBe(AppFramework::NEXTJS)
        ->and($config->getCacheDriver())->toBe(CacheDriver::REDIS);
});

// ── Next.js config patch logic tests ─────────────────────────────────────────

test('NextjsNewCommand::patchNextConfig injects standalone output into a simple config', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    $original = <<<'TS'
import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  reactStrictMode: true,
};

export default nextConfig;
TS;
    file_put_contents("$dir/next.config.ts", $original);

    $command = new NextjsNewCommand;

    // Use reflection to call the protected method
    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('patchNextConfig');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    $patched = file_get_contents("$dir/next.config.ts");
    expect($patched)->toContain("'standalone'")
        ->toContain('cacheMaxMemorySize: 0')
        // Cache handler wired production-only via require.resolve.
        ->toContain('cacheHandler')
        ->toContain("require.resolve('./cache-handler.mjs')")
        ->toContain("process.env.NODE_ENV === 'production'");

    $temporaryDirectory->delete();
});

test('NextjsNewCommand::generateHealthRoute creates the route file', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    mkdir("$dir/app", 0o755, true);

    $command = new NextjsNewCommand;

    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('generateHealthRoute');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    expect(file_exists("$dir/app/api/health/route.ts"))->toBeTrue();
    $content = file_get_contents("$dir/app/api/health/route.ts");
    expect($content)->toContain('GET')
        ->toContain('status');

    $temporaryDirectory->delete();
});

// ── Scaffold runs under the resolved container runtime (Docker or Podman) ─────

test('nextjs:new pulls and runs create-next-app through the resolved runtime', function (string $runtime): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $old = (string) getcwd();
    chdir($temporaryDirectory->path());
    putenv("LARAKUBE_CONTAINER_RUNTIME={$runtime}");

    try {
        $commands = nextjsScaffoldCommands(fn () => $this->artisan('nextjs:new next-demo --fast')->run());
    } finally {
        chdir($old);
        // Restore the suite-wide default pinned in TestCase::setUp().
        putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
    }

    $pull = collect($commands)->first(fn ($c): bool => str_contains($c, ' pull '));
    $scaffold = collect($commands)->first(fn ($c): bool => str_contains($c, 'create-next-app'));

    expect($pull)->toContain("{$runtime} pull")
        ->toContain('node:24-alpine')
        ->and($scaffold)->toContain("{$runtime} run")
        ->toContain('create-next-app')
        // The stack the follow-up steps depend on stays pinned even scripted.
        ->toContain('--ts')
        ->toContain('--app')
        ->toContain('--no-src-dir')
        // A persistent npm cache volume so create-next-app isn't re-downloaded
        // every run — a named volume (chown-free) under both runtimes.
        ->toContain('larakube-npm-cache:/npm-cache')
        ->toContain('npm_config_cache=/npm-cache');
})->with(['docker', 'podman']);

// ── Next.js K8s manifest pipeline (orchestration regression) ─────────────────

test('generateNextjsManifests writes dev-server + preview overlays without the Laravel stack', function (): void {
    // Regression: Next.js (non-static, null serverVariation) was routed into the
    // PHP base stack, which crashed on getServerVariation()->getPodName(). It now
    // gets its own self-contained overlays — dev server locally, standalone image
    // for preview — like the static frameworks.
    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($tmp->path('package.json'), json_encode(['scripts' => ['dev' => 'next dev', 'build' => 'next build']]));
    // The .env wireDatabaseEnv() writes at scaffold time; the Secret is built
    // from it (self-hosted values here, since this config never joined Plex).
    file_put_contents($tmp->path('.env'), implode("\n", [
        'DATABASE_URL="postgresql://next-demo:pw@next-demo-postgresql:5432/next-demo"',
        'REDIS_URL="redis://redis:6379"',
        'DB_DATABASE=next-demo',
        'DB_USERNAME=next-demo',
        'DB_PASSWORD=pw',
    ]));

    $config = new ConfigData;
    $config->setIsScaffolding(true);
    $config->framework = AppFramework::NEXTJS;
    $config->setName('next-demo');
    $config->setPath($tmp->path());
    $config->setEnvironments(['local']);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);

    $command = new NextjsNewCommand;
    $method = (new ReflectionClass($command))->getMethod('generateNextjsManifests');
    $method->setAccessible(true);
    $method->invoke($command, $config);

    $k8s = $config->getK8sPath();
    $devServer = (string) file_get_contents("$k8s/overlays/local/dev-server.yaml");
    $previewDeploy = (string) file_get_contents("$k8s/overlays/local/preview/deployment.yaml");
    $previewKustomize = (string) file_get_contents("$k8s/overlays/local/preview/kustomization.yaml");

    // Local: the framework's own dev server, reachable on 3000.
    expect($devServer)->toContain('containerPort: 3000')
        // Preview: the standalone image workload, web-preview names, :preview tag.
        ->and($previewDeploy)->toContain('name: web-preview')
        ->toContain('path: /api/health')
        // Production standalone → DATABASE_URL/REDIS_URL come from the Secret,
        // and Prisma migrations run in an init container before the server.
        ->toContain('prisma-migrate')
        ->toContain('secretRef')
        ->toContain('-nextjs-secrets')
        ->and($previewKustomize)->toContain('newTag: preview')
        ->toContain('secret.yaml')
        // No Plex here (a fresh config) → self-hosted DB + Redis pods are rendered.
        ->toContain('redis.yaml')
        ->toContain('database.yaml')
        ->and(file_exists("$k8s/overlays/local/preview/secret.yaml"))->toBeTrue()
        ->and(file_exists("$k8s/overlays/local/preview/database.yaml"))->toBeTrue()
        // Never the PHP deployment.
        ->and($previewDeploy)->not->toContain('serversideup');

    // The Secret carries the connection strings from .env; the DB pod is the
    // chosen engine wired to that Secret.
    $secret = (string) file_get_contents("$k8s/overlays/local/preview/secret.yaml");
    $database = (string) file_get_contents("$k8s/overlays/local/preview/database.yaml");
    expect($secret)->toContain('next-demo-nextjs-secrets')
        ->toContain('DATABASE_URL')
        ->toContain('postgresql://next-demo:pw@next-demo-postgresql')
        ->and($database)->toContain('POSTGRES_PASSWORD')
        ->toContain('next-demo-nextjs-secrets');
});

// ── Next.js Dockerfile (orchestration regression) ────────────────────────────

test('nextjs:new renders a Node standalone Dockerfile, not the PHP one', function (): void {
    // Regression: Next.js is a Node SERVER with no serverVariation, so
    // generateDockerfiles() rendering docker.php crashed orchestration with
    // "Attempt to read property value on null" (View: docker.php line 7, the
    // getServerVariation()->value in the serversideup FROM). Next.js now gets
    // docker.nextjs, which never touches it.
    $config = new ConfigData;
    $config->framework = AppFramework::NEXTJS;
    $config->setName('next-demo');
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setCacheDriver(CacheDriver::REDIS);

    // The crash condition on a Next.js config.
    expect($config->getServerVariation())->toBeNull();

    $rendered = view('docker.nextjs', ['config' => $config])->render();

    // The official standalone shape: multi-stage Node build ending in a `deploy`
    // stage that runs `node server.js` from the standalone output on port 3000.
    expect($rendered)->toContain('FROM node:24-alpine AS deploy')
        ->toContain('.next/standalone')
        ->toContain('"node", "server.js"')
        ->toContain('EXPOSE 3000');
});

test('NextjsNewCommand::generateCacheHandler creates cache-handler.mjs', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    $command = new NextjsNewCommand;

    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('generateCacheHandler');
    $method->setAccessible(true);
    $method->invoke($command, $dir);

    expect(file_exists("$dir/cache-handler.mjs"))->toBeTrue();
    $content = file_get_contents("$dir/cache-handler.mjs");
    // The maintained, Next 16-compatible handler — not the capped @neshca one.
    expect($content)->toContain('@fortedigital/nextjs-cache-handler')
        ->toContain('redis-strings')
        ->toContain('REDIS_URL')
        ->not->toContain('@neshca');

    $temporaryDirectory->delete();
});
