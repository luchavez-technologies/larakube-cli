<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\PhpVersion;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Record every command the Bedrock scaffold shells out through. Tests never get
 * a TTY, so runBedrockNew() takes its scripted branch (Process, not passthru) —
 * which is what makes the container commands observable rather than a real
 * `docker run` leaking out of the suite.
 *
 * @return list<string>
 */
function wordpressScaffoldCommands(callable $run): array
{
    $recorded = [];

    Process::fake(['*' => function ($process) use (&$recorded) {
        $recorded[] = (string) $process->command;

        return Process::result(output: '');
    }]);

    $run();

    return $recorded;
}

// ── wordpress:new Command Tests ──────────────────────────────────────────────

test('wordpress:new command is registered and has correct signature', function (): void {
    $this->artisan('wordpress:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('wordpress:new');
});

test('wordpress:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('wordpress:new')
        ->and($commands['wordpress:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Scaffold runs under the resolved container runtime (Docker or Podman) ─────

test('wordpress:new pulls and runs composer create-project through the resolved runtime', function (string $runtime): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $old = (string) getcwd();
    chdir($temporaryDirectory->path());
    putenv("LARAKUBE_CONTAINER_RUNTIME={$runtime}");

    try {
        // --no-plex keeps this a pure local scaffold (no cluster provisioning).
        $commands = wordpressScaffoldCommands(fn () => $this->artisan('wordpress:new wp-demo --fast --no-plex')->run());
    } finally {
        chdir($old);
        // Restore the suite-wide default pinned in TestCase::setUp().
        putenv('LARAKUBE_CONTAINER_RUNTIME=docker');
    }

    $pull = collect($commands)->first(fn ($c): bool => str_contains($c, ' pull '));
    $scaffold = collect($commands)->first(fn ($c): bool => str_contains($c, 'create-project roots/bedrock'));

    expect($pull)->toContain("{$runtime} pull")
        ->and($scaffold)->toContain("{$runtime} run")
        ->toContain('create-project roots/bedrock')
        // Scripted (no TTY) path drops -it so `<runtime> run` does not fail.
        ->not->toContain(' -it ');
})->with(['docker', 'podman']);

// ── Driver Compatibility Matrix — WordPress ───────────────────────────────────

test('AppFramework WORDPRESS framework value is correct', function (): void {
    expect(AppFramework::WORDPRESS->value)->toBe('wordpress')
        ->and(AppFramework::WORDPRESS->getLabel())->toBe('WordPress (Bedrock)')
        ->and(AppFramework::WORDPRESS->healthProbePath())->toBe('/wp/wp-includes/version.php');
});

test('WordPress DatabaseDriver matrix: only MySQL and MariaDB are valid', function (): void {
    // Drivers that WordPress officially supports
    $supported = [DatabaseDriver::MYSQL, DatabaseDriver::MARIADB];
    $unsupported = [DatabaseDriver::POSTGRESQL, DatabaseDriver::SQLITE];

    foreach ($supported as $driver) {
        expect($supported)->toContain($driver);
    }

    foreach ($unsupported as $driver) {
        expect($supported)->not->toContain($driver);
    }
});

test('WordPress CacheDriver matrix: database driver is hidden', function (): void {
    // Per plan §2b: WordPress cannot use database CacheDriver (WP transients use wp_options)
    $allowed = [CacheDriver::REDIS, CacheDriver::MEMCACHED];
    $hidden = [CacheDriver::DATABASE];

    foreach ($allowed as $driver) {
        expect($allowed)->toContain($driver);
    }

    foreach ($hidden as $driver) {
        expect($hidden)->toContain($driver);
    }
});

test('WordPress StorageDriver: all S3-compatible drivers are valid (mandatory)', function (): void {
    $mandatory = [StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE];

    expect($mandatory)->toHaveCount(3)->toContainOnlyInstancesOf(StorageDriver::class);
});

// ── WordPress ConfigData Integration ─────────────────────────────────────────

test('ConfigData accepts AppFramework::WORDPRESS framework', function (): void {
    $config = new ConfigData;
    $config->framework = AppFramework::WORDPRESS;

    expect($config->framework)->toBe(AppFramework::WORDPRESS)
        ->and($config->framework->value)->toBe('wordpress');
});

// ── WordPress Server Variation (Dockerfile regression) ───────────────────────

test('wordpress:new wires the architectural engine used by scaffolding', function (): void {
    // Regression: orchestrateProjectScaffolding() calls installComponents(),
    // which lives in InteractsWithArchitecturalEngine. wordpress:new did not
    // pull that trait in, so scaffolding crashed with a missing method.
    $reflection = new ReflectionClass(App\Commands\Wordpress\WordpressNewCommand::class);

    expect($reflection->getTraitNames())->toContain('App\Traits\InteractsWithArchitecturalEngine');
});

test('WordPress config pins the Nginx server variation so the Dockerfile renders', function (): void {
    // Regression: wordpress:new built ConfigData without a serverVariation, so
    // docker.php line 7 read ->value on null and scaffolding crashed before any
    // manifests were generated. Plan §4b mandates the SSU fpm-nginx base image.
    $config = new ConfigData;
    $config->framework = AppFramework::WORDPRESS;
    $config->phpVersion = PhpVersion::PHP_8_4;
    $config->serverVariation = ServerVariation::FPM_NGINX;
    $config->setName('wp');
    $config->setDatabase(DatabaseDriver::MYSQL);
    $config->setCacheDriver(CacheDriver::REDIS);
    $config->setObjectStorage(StorageDriver::MINIO);

    $rendered = view('docker.php', ['config' => $config])->render();

    expect($rendered)->toContain('serversideup/php:8.4-fpm-nginx')
        ->and(ServerVariation::FPM_NGINX->value)->toBe('fpm-nginx');
});

function wordpressManifestConfig(AppFramework $framework): ConfigData
{
    $config = new ConfigData;
    $config->framework = $framework;
    $config->phpVersion = PhpVersion::PHP_8_4;
    $config->serverVariation = ServerVariation::FPM_NGINX;
    $config->setName('wp');
    $config->setDatabase(DatabaseDriver::MYSQL);
    $config->setCacheDriver(CacheDriver::REDIS);
    $config->setObjectStorage(StorageDriver::MINIO);

    return $config;
}

test('the WordPress web pod turns off serversideup autorun, since there is no artisan', function (): void {
    $manifests = generateManifests(wordpressManifestConfig(AppFramework::WORDPRESS));

    expect($manifests)->toMatch('/name: AUTORUN_ENABLED\s+value: "false"/')
        ->not->toMatch('/name: AUTORUN_ENABLED\s+value: "true"/')
        ->not->toMatch('/name: AUTORUN_LARAVEL_MIGRATION\s/');
});

test('the WordPress web pod serves Bedrock\'s web root and probes a file that exists there', function (): void {
    $manifests = generateManifests(wordpressManifestConfig(AppFramework::WORDPRESS));

    expect($manifests)->toMatch('/name: NGINX_WEBROOT\s+value: \/var\/www\/html\/web\n/')
        ->not->toContain('path: /up')
        ->and(substr_count($manifests, 'path: /wp/wp-includes/version.php'))->toBe(3);
});

test('the WordPress web pod gives Bedrock DB_NAME and DB_USER from the keys every writer fills', function (): void {
    $manifests = generateManifests(wordpressManifestConfig(AppFramework::WORDPRESS));

    expect($manifests)->toMatch('/name: DB_NAME\s+value: "\$\(DB_DATABASE\)"/')
        ->toMatch('/name: DB_USER\s+value: "\$\(DB_USERNAME\)"/')
        ->and(generateManifests(wordpressManifestConfig(AppFramework::LARAVEL)))->not->toContain('DB_NAME');
});

test('the env roll-up gives Bedrock the URL and environment keys it refuses to boot without', function (): void {
    $config = wordpressManifestConfig(AppFramework::WORDPRESS);
    $local = $config->getAllPublicEnvironmentVariables('local');
    $bedrockKeys = array_flip(['WP_ENV', 'WP_HOME', 'WP_SITEURL']);

    expect($local['WP_ENV'])->toBe('development')
        ->and($local['WP_HOME'])->toBe($config->getAppUrl('local'))
        ->and($local['WP_SITEURL'])->toBe($config->getAppUrl('local').'/wp')
        ->and($config->getAllPublicEnvironmentVariables('staging')['WP_ENV'])->toBe('staging')
        ->and($config->getAllPublicEnvironmentVariables('production')['WP_ENV'])->toBe('production')
        ->and(array_intersect_key(wordpressManifestConfig(AppFramework::LARAVEL)->getAllPublicEnvironmentVariables('local'), $bedrockKeys))->toBeEmpty();
});

test('WordPress keeps its uploads on the volume and gets none of Laravel\'s storage mounts', function (): void {
    $wordpress = generateManifests(wordpressManifestConfig(AppFramework::WORDPRESS));
    $laravel = generateManifests(wordpressManifestConfig(AppFramework::LARAVEL));

    expect($wordpress)->toMatch('/mountPath: \/var\/www\/html\/web\/app\/uploads\s+subPath: uploads/')
        ->not->toContain('mountPath: /var/www/html/storage/')
        ->not->toContain('mountPath: /var/www/html/bootstrap/cache')
        ->and($laravel)->toContain('mountPath: /var/www/html/storage/logs')
        ->not->toContain('web/app/uploads');
});

test('a Laravel web pod keeps autorun, its migrations, the default web root and /up', function (): void {
    $manifests = generateManifests(wordpressManifestConfig(AppFramework::LARAVEL));

    expect($manifests)->toMatch('/name: AUTORUN_ENABLED\s+value: "true"/')
        ->toMatch('/name: AUTORUN_LARAVEL_MIGRATION\s+value: "true"/')
        ->not->toContain('NGINX_WEBROOT')
        ->and(substr_count($manifests, 'path: /up'))->toBe(3);
});

// ── wp:new Alias Tests ───────────────────────────────────────────────────────

test('wordpress:new exposes the wp:new alias', function (): void {
    $kernel = app(Kernel::class);

    expect($kernel->all())->toHaveKey('wp:new')
        ->and($kernel->all()['wp:new']->getName())->toBe('wordpress:new')
        ->and($kernel->all()['wordpress:new']->getAliases())->toContain('wp:new');
});

test('wp:new alias resolves to the wordpress:new command', function (): void {
    $this->artisan('wp:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('wordpress:new');
});
