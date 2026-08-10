<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Traits\ReadsPlexCredentials;

function plexCredReader(): object
{
    return new class
    {
        use ReadsPlexCredentials;

        public function read(ConfigData $config, string $path, string $env): array
        {
            return $this->plexTenantCredentials($config, $path, $env);
        }
    };
}

function tenantConfig(string $env, array $plex): ConfigData
{
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments[$env] = EnvironmentData::from(['plex' => $plex]);

    return $config;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/lk-plex-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    @rmdir($this->dir);
});

test('returns [] when the project is not a Plex tenant for the env', function () {
    $config = tenantConfig('production', []);   // empty plex array = not a tenant
    file_put_contents($this->dir.'/.env.production', "DB_HOST=mysql.larakube-plex.svc.cluster.local\n");

    expect(plexCredReader()->read($config, $this->dir, 'production'))->toBe([]);
});

test('returns [] when the env file is missing', function () {
    $config = tenantConfig('production', ['mysql']);

    expect(plexCredReader()->read($config, $this->dir, 'production'))->toBe([]);
});

test('reads DB, Redis and S3 credentials from .env.{env} for a cloud tenant', function () {
    $config = tenantConfig('production', ['mysql', 'redis']);
    file_put_contents($this->dir.'/.env.production', implode("\n", [
        'DB_HOST=mysql.larakube-plex.svc.cluster.local',
        'DB_PORT=3306',
        'DB_DATABASE=demo',
        'DB_USERNAME=demo',
        'DB_PASSWORD=s3cret',
        'REDIS_HOST=redis.larakube-plex.svc.cluster.local',
        'REDIS_PORT=6379',
        'REDIS_DB=3',
        'AWS_BUCKET=demo',
        'AWS_ENDPOINT=http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        'AWS_ACCESS_KEY_ID=key',
        'AWS_SECRET_ACCESS_KEY=secret',
    ]));

    $creds = plexCredReader()->read($config, $this->dir, 'production');

    expect($creds['database']['Host'])->toBe('mysql.larakube-plex.svc.cluster.local:3306')
        ->and($creds['database']['Password'])->toBe('s3cret')
        ->and($creds['redis']['Host'])->toBe('redis.larakube-plex.svc.cluster.local:6379')
        ->and($creds['redis']['DB index'])->toBe('3')
        ->and($creds['s3']['Bucket'])->toBe('demo')
        ->and($creds['s3']['Secret'])->toBe('secret');
});

test('omits a redis password of literal "null"', function () {
    $config = tenantConfig('production', ['redis']);
    file_put_contents($this->dir.'/.env.production', implode("\n", [
        'REDIS_HOST=redis.larakube-plex.svc.cluster.local',
        'REDIS_PASSWORD=null',
    ]));

    $creds = plexCredReader()->read($config, $this->dir, 'production');

    expect($creds['redis'])->not->toHaveKey('Password');
});

test('local env reads .env, not .env.local', function () {
    $config = tenantConfig('local', ['mysql']);
    file_put_contents($this->dir.'/.env', "DB_HOST=mysql.larakube-plex.svc.cluster.local\nDB_PASSWORD=localpass\n");

    $creds = plexCredReader()->read($config, $this->dir, 'local');

    expect($creds['database']['Password'])->toBe('localpass');
});
