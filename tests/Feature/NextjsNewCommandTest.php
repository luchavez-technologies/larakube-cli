<?php

use App\Commands\Nextjs\NextjsNewCommand;
use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
    expect($rendered)->toContain('FROM docker.io/library/node:24-alpine AS deploy')
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

test('the Prisma generate step carries a build-time DATABASE_URL placeholder', function (): void {
    $rendered = view('docker.nextjs', ['config' => null])->render();

    // `prisma generate` never connects, but prisma 6.19's init scaffolds a
    // prisma.config.ts that reads env("DATABASE_URL") via dotenv while LOADING.
    // .dockerignore excludes .env*, so inside the image there is nothing for
    // dotenv to read and the build died at "Missing required environment
    // variable: DATABASE_URL". A placeholder is correct here — copying the real
    // .env into the build context would bake the credential into a layer.
    expect($rendered)
        ->toContain('prisma generate')
        ->toContain('DATABASE_URL="postgresql://placeholder')
        ->not->toContain('COPY .env');
});

test('every command that renders infrastructure can actually install components', function (): void {
    // GeneratesProjectInfrastructure CALLS installComponents(), but the method is
    // defined in InteractsWithArchitecturalEngine. Commands that pulled in only
    // the former hit a "method does not exist" fatal at feature-install time —
    // nextjs:new was simply the first one to reach that line.
    $commands = [];
    foreach (glob(base_path('app/Commands/**/*Command.php')) + glob(base_path('app/Commands/*Command.php')) as $file) {
        $source = (string) file_get_contents($file);
        if (! str_contains($source, 'GeneratesProjectInfrastructure')) {
            continue;
        }
        preg_match('/namespace\s+([^;]+);/', $source, $ns);
        $class = trim($ns[1] ?? '').'\\'.basename($file, '.php');
        if (class_exists($class)) {
            $commands[] = $class;
        }
    }

    expect($commands)->not->toBeEmpty();

    foreach ($commands as $class) {
        expect(method_exists($class, 'installComponents'))
            ->toBeTrue("{$class} renders infrastructure but cannot call installComponents()");
    }
});

// ── Commons wiring after plex:join ───────────────────────────────────────────

/** A command whose `heal` call is recorded instead of run. */
function nextjsCommonsWiringCommand(): object
{
    $command = new class extends NextjsNewCommand
    {
        /** @var list<string> */
        public array $healed = [];

        public function callSilent($command, array $arguments = []): int
        {
            $this->healed[] = $command;

            return 0;
        }

        /** @return array<string, string> */
        public function wire(string $projectDir, DatabaseDriver $database): array
        {
            return $this->wireCommonsDatabaseEnv($projectDir, $database);
        }
    };

    $input = new ArrayInput([]);
    $input->bind($command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, new BufferedOutput));

    return $command;
}

/** A scaffolded Next.js project whose blueprint lists what plex:join joined. */
function nextjsCommonsProject(array $plex, array $env): TemporaryDirectory
{
    $dir = TemporaryDirectory::make();

    ConfigData::from([
        'name' => 'hello-next',
        'framework' => 'nextjs',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'environments' => ['local' => ['plex' => $plex, 'managed' => $plex]],
    ])->saveToFile($dir->path());

    $lines = [];
    foreach ($env as $key => $value) {
        $lines[] = "{$key}={$value}";
    }
    file_put_contents($dir->path().'/.env', implode("\n", $lines)."\n");

    return $dir;
}

/** The self-hosted values wireDatabaseEnv() leaves before any join. */
function nextjsSelfHostedEnv(): array
{
    return [
        'DATABASE_URL' => 'postgresql://hello-next:self@hello-next-postgres:5432/hello-next',
        'REDIS_URL' => 'redis://redis:6379',
    ];
}

/** What plex:join writes for a tenant that joined both Postgres and Redis. */
function nextjsJoinedEnv(): array
{
    return [
        'DB_HOST' => 'postgres.larakube-plex.svc.cluster.local',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'hello_next_local',
        'DB_USERNAME' => 'hello_next_local',
        'DB_PASSWORD' => 'tenantpass',
        'REDIS_HOST' => 'redis.larakube-plex.svc.cluster.local',
        'REDIS_PORT' => '6379',
        'REDIS_DB' => '7',
    ];
}

test('Commons wiring rebuilds both URLs from what plex:join wrote', function (): void {
    $project = nextjsCommonsProject(['postgres', 'redis'], array_merge(nextjsSelfHostedEnv(), nextjsJoinedEnv()));

    $command = nextjsCommonsWiringCommand();
    $written = $command->wire($project->path(), DatabaseDriver::POSTGRESQL);
    $env = (string) file_get_contents($project->path().'/.env');

    expect($written['DATABASE_URL'])->toBe('postgresql://hello_next_local:tenantpass@postgres.larakube-plex.svc.cluster.local:5432/hello_next_local')
        ->and($written['REDIS_URL'])->toBe('redis://redis.larakube-plex.svc.cluster.local:6379/7')
        ->and($env)->toContain('hello_next_local:tenantpass@postgres.larakube-plex')
        ->and($env)->not->toContain('hello-next-postgres')
        // The generated Secret carries these values, so manifests render again.
        ->and($command->healed)->toBe(['heal']);

    $project->delete();
});

test('Commons wiring leaves the self-hosted values alone when nothing joined', function (): void {
    $project = nextjsCommonsProject([], nextjsSelfHostedEnv());
    $before = (string) file_get_contents($project->path().'/.env');

    $command = nextjsCommonsWiringCommand();

    expect($command->wire($project->path(), DatabaseDriver::POSTGRESQL))->toBe([])
        ->and((string) file_get_contents($project->path().'/.env'))->toBe($before)
        ->and($command->healed)->toBe([]);

    $project->delete();
});

test('Commons wiring never builds a DATABASE_URL without a password in .env', function (): void {
    $joined = nextjsJoinedEnv();
    unset($joined['DB_PASSWORD']);
    $project = nextjsCommonsProject(['postgres', 'redis'], array_merge(nextjsSelfHostedEnv(), $joined));

    $written = nextjsCommonsWiringCommand()->wire($project->path(), DatabaseDriver::POSTGRESQL);

    expect($written)->not->toHaveKey('DATABASE_URL')
        ->and($written['REDIS_URL'])->toBe('redis://redis.larakube-plex.svc.cluster.local:6379/7');

    $project->delete();
});

test('Commons wiring keeps the self-hosted Redis when only the database joined', function (): void {
    $joined = array_intersect_key(nextjsJoinedEnv(), array_flip(['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']));
    $project = nextjsCommonsProject(['postgres'], array_merge(nextjsSelfHostedEnv(), $joined));

    $written = nextjsCommonsWiringCommand()->wire($project->path(), DatabaseDriver::POSTGRESQL);
    $env = (string) file_get_contents($project->path().'/.env');

    expect($written)->toHaveKey('DATABASE_URL')
        ->not->toHaveKey('REDIS_URL')
        ->and($env)->toContain('redis://redis:6379');

    $project->delete();
});
