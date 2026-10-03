<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Services\Project\BackingServices;
use Illuminate\Support\Facades\Artisan;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function backingConfig(array $plex = [], array $managed = []): ConfigData
{
    $config = new ConfigData;
    $config->setName('blog');
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setCacheDriver(CacheDriver::REDIS);
    $config->setObjectStorage(StorageDriver::SEAWEEDFS);
    $config->addEnvironment('local');
    $config->environments['local'] = new EnvironmentData(plex: $plex, managed: $managed);

    return $config;
}

$env = [
    'DB_HOST' => 'postgres.larakube-plex.svc.cluster.local', 'DB_PORT' => '5432', 'DB_DATABASE' => 'blog_local', 'DB_USERNAME' => 'blog_local', 'DB_PASSWORD' => 'pw',
    'REDIS_HOST' => 'redis.larakube-plex.svc.cluster.local', 'REDIS_PORT' => '6379', 'REDIS_DB' => '3',
    'AWS_BUCKET' => 'blog-local', 'AWS_ENDPOINT' => 'http://seaweedfs:8333', 'AWS_ACCESS_KEY_ID' => 'larakube', 'AWS_SECRET_ACCESS_KEY' => 'sk',
];

test('services that joined the Commons say so, with where and how the app connects', function () use ($env): void {
    $services = collect((new BackingServices)->describe(backingConfig(['postgres', 'redis', 'seaweedfs']), 'local', $env))->keyBy('kind');

    expect($services['database']['mode'])->toBe('commons')
        ->and($services['database']['name'])->toBe('PostgreSQL')
        ->and(collect($services['database']['details'])->firstWhere('label', 'Username')['value'])->toBe('blog_local')
        ->and(collect($services['cache']['details'])->firstWhere('label', 'Database index')['value'])->toBe('3')
        ->and($services['storage']['mode'])->toBe('commons')
        ->and(collect($services['storage']['details'])->firstWhere('label', 'Bucket')['value'])->toBe('blog-local')
        ->and($services['search']['mode'])->toBe('none');
});

test('secrets are withheld unless asked for', function () use ($env): void {
    $hidden = collect((new BackingServices)->describe(backingConfig(['postgres']), 'local', $env))->keyBy('kind');
    $shown = collect((new BackingServices)->describe(backingConfig(['postgres']), 'local', $env, reveal: true))->keyBy('kind');

    expect(collect($hidden['database']['details'])->firstWhere('label', 'Password'))->toBe(['label' => 'Password', 'value' => null, 'secret' => true])
        ->and(collect($shown['database']['details'])->firstWhere('label', 'Password')['value'])->toBe('pw')
        ->and(collect($hidden['storage']['details'])->firstWhere('label', 'Secret key')['value'])->toBeNull();
});

test('a service not on the Commons is a pod, managed, or a file depending on the blueprint', function (): void {
    $pod = collect((new BackingServices)->describe(backingConfig(), 'local', []))->keyBy('kind');
    $managed = collect((new BackingServices)->describe(backingConfig(managed: ['postgres']), 'local', []))->keyBy('kind');

    $sqlite = backingConfig()->setDatabase(DatabaseDriver::SQLITE);
    $file = collect((new BackingServices)->describe($sqlite, 'local', []))->keyBy('kind');

    expect($pod['database']['mode'])->toBe('pod')
        ->and($managed['database']['mode'])->toBe('managed')
        ->and($file['database']['mode'])->toBe('file');
});

test('services:show --json reads the environment\'s own .env and keeps secrets out by default', function (): void {
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $project = $directory->path('blog');
    $config = backingConfig(['postgres', 'redis', 'seaweedfs']);
    $config->setPath($project, true);
    file_put_contents("{$project}/.larakube.json", json_encode($config->toArray()));
    file_put_contents("{$project}/.env", "DB_HOST=postgres.larakube-plex\nDB_USERNAME=blog_local\nDB_PASSWORD=\"pw\"\nAWS_BUCKET=blog-local\n");

    $previous = getcwd();
    chdir($project);

    try {
        Artisan::call('services:show local --json');
        $result = json_decode(trim(Artisan::output()), true);

        Artisan::call('services:show local --json --reveal');
        $revealed = json_decode(trim(Artisan::output()), true);
    } finally {
        chdir($previous);
    }

    expect($result['success'])->toBeTrue()
        ->and($result['commons'])->toBeTrue()
        ->and($result['services'][0]['mode'])->toBe('commons')
        ->and(collect($result['services'][0]['details'])->firstWhere('label', 'Password')['value'])->toBeNull()
        ->and(collect($revealed['services'][0]['details'])->firstWhere('label', 'Password')['value'])->toBe('pw');
});

test('services:show says so outside a project', function (): void {
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $previous = getcwd();
    chdir($directory->path());

    try {
        $exit = Artisan::call('services:show local --json');
        $result = json_decode(trim(Artisan::output()), true);
    } finally {
        chdir($previous);
    }

    expect($exit)->toBe(1)->and($result)->toBe(['success' => false, 'error' => 'Not a LaraKube project.']);
});
