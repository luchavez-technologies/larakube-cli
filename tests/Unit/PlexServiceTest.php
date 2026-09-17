<?php

/** PlexService's pure helpers, called directly on the object. */

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Services\PlexService;
use Illuminate\Support\Facades\Process;

test('the Commons namespace is a constant, not configuration', function (): void {
    expect(PlexService::NAMESPACE)->toBe('larakube-plex');
});

test('tenant identifiers are safe SQL identifiers', function (): void {
    $plex = new PlexService;

    expect($plex->plexTenantIdentifier('app-one'))->toBe('app_one')
        ->and($plex->plexTenantIdentifier('My App!'))->toBe('my_app')
        ->and($plex->plexTenantIdentifier('  Drift.Labs  '))->toBe('drift_labs')
        // SQL identifiers must start with a letter.
        ->and($plex->plexTenantIdentifier('1app'))->toBe('app_1app')
        ->and($plex->plexTenantIdentifier(''))->toBe('app_');
});

test('non-production environments get their own suffixed tenant', function (): void {
    $plex = new PlexService;

    // Production stays un-suffixed for backwards compatibility with the
    // single-env Plex setups that predate per-env tenants.
    expect($plex->plexTenantIdentifier('app-one', 'production'))->toBe('app_one')
        ->and($plex->plexTenantIdentifier('app-one', 'local'))->toBe('app_one_local')
        ->and($plex->plexTenantIdentifier('app-one', 'staging'))->toBe('app_one_staging');
});

test('tenant identifiers stay inside Postgres 63-character cap', function (): void {
    $plex = new PlexService;
    $long = str_repeat('a', 100);

    expect($plex->plexTenantIdentifier($long))->toHaveLength(63)
        ->and($plex->plexTenantIdentifier($long, 'staging'))->toHaveLength(63)
        ->and($plex->plexTenantIdentifier($long, 'staging'))->toEndWith('_staging');
});

test('bucket names are DNS-safe, since S3 rejects the underscores tenants use', function (): void {
    $plex = new PlexService;

    expect($plex->plexBucketName('app_five'))->toBe('app-five')
        ->and($plex->plexBucketName('APP__Five__'))->toBe('app-five')
        // S3 requires at least three characters.
        ->and($plex->plexBucketName('a'))->toBe('lk-a')
        ->and($plex->plexBucketName(str_repeat('b', 100)))->toHaveLength(63);
});

test('a project contributes only the Commons services its drivers map to', function (): void {
    $plex = new PlexService;

    $config = ConfigData::from([
        'name' => 'demo',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'objectStorage' => 'seaweedfs',
        'scoutDriver' => 'meilisearch',
        'environments' => ['local' => []],
    ]);

    expect($plex->projectCommonsServices($config))
        ->toContain('postgres', 'redis', 'seaweedfs', 'meilisearch');
});

test('SQLite contributes nothing shareable to the Commons', function (): void {
    $plex = new PlexService;

    $config = ConfigData::from([
        'name' => 'demo',
        'database' => 'sqlite',
        'environments' => ['local' => []],
    ]);

    expect($plex->projectCommonsServices($config))->not->toContain('sqlite');
});

test('the tenant SQL round-trips through create and drop', function (): void {
    $plex = new PlexService;

    expect($plex->buildPostgresTenantSql('app_one', 'app_one', 's3cret'))
        ->toContain('app_one')
        ->and($plex->buildDropTenantSql('app_one', 'app_one'))
        ->toContain('DROP DATABASE')
        ->toContain('app_one');
});

test('the context is accepted but irrelevant to the pure helpers', function (): void {
    // Pure helpers must not depend on which cluster the service targets.
    $local = new PlexService;
    $cloud = new PlexService('larakube-203-0-113-1');

    expect($cloud->plexTenantIdentifier('app-one', 'staging'))
        ->toBe($local->plexTenantIdentifier('app-one', 'staging'))
        ->and($cloud->plexBucketName('app_one'))->toBe($local->plexBucketName('app_one'));
});

test('kubectl targets the constructor context, or the current context when none is given', function (): void {
    $prefix = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

    expect((new PlexService)->kubectl())->toBe($prefix)
        ->and((new PlexService(''))->kubectl())->toBe($prefix)
        ->and((new PlexService('larakube-203-0-113-1'))->kubectl())->toBe($prefix." --context 'larakube-203-0-113-1'");
});

test('contextReachable probes cluster-info on the constructor context', function (): void {
    $kubectl = (new PlexService('orbstack'))->kubectl();

    Process::fake(["{$kubectl} cluster-info --request-timeout=8s" => Process::result(exitCode: 0), '*' => Process::result(exitCode: 1)]);

    expect((new PlexService('orbstack'))->contextReachable())->toBeTrue();
});

test('contextReachable is false when cluster-info fails', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect((new PlexService('orbstack'))->contextReachable())->toBeFalse();
});

test('registry reads the plex-registry ConfigMap on the constructor context', function (): void {
    Process::fake(['*get configmap plex-registry -n larakube-plex*' => Process::result(output: '{"tenants":{"shop":{"db":"shop","redis_index":2}}}')]);

    expect((new PlexService('orbstack'))->registry())->toBe(['tenants' => ['shop' => ['db' => 'shop', 'redis_index' => 2]]]);
    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "--context 'orbstack' get configmap plex-registry -n larakube-plex"));
});

test('a missing or unreadable registry reads as empty', function (): void {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: ''))
        ->push(Process::result(output: 'not json')),
    ]);
    $service = new PlexService('orbstack');

    expect($service->registry())->toBeEmpty()
        ->and($service->registry())->toBeEmpty();
});

test('saveRegistry applies the registry as a ConfigMap on the constructor context', function (): void {
    Process::fake(['*' => Process::result(output: 'configmap/plex-registry configured')]);

    (new PlexService('orbstack'))->saveRegistry(['tenants' => ['shop' => ['db' => 'shop']]]);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "--context 'orbstack' create configmap plex-registry -n larakube-plex --from-file=registry.json=")
        && str_contains((string) $process->command, '--dry-run=client -o yaml | '));
});

test('registry transforms add and remove tenants and list the Redis slots in use', function (): void {
    $plex = new PlexService;
    $registry = $plex->registryAdd([], 'shop', ['db' => 'shop', 'redis_index' => 3]);
    $registry = $plex->registryAdd($registry, 'blog', ['db' => 'blog', 'redis_index' => 0]);
    $registry = $plex->registryAdd($registry, 'files', ['s3_bucket' => 'files']);

    expect($plex->registryUsedRedisIndexes($registry))->toBe([3, 0])
        ->and(array_keys($plex->registryRemove($registry, 'shop')['tenants']))->toBe(['blog', 'files']);
});

test('the lowest free Redis slot is allocated, and none once all 16 are used', function (): void {
    $plex = new PlexService;

    expect($plex->allocateRedisDbIndex([0, 1, 3]))->toBe(2)
        ->and($plex->allocateRedisDbIndex(range(0, 15)))->toBeNull();
});

test('context() reports the kube-context the service was built for', function (): void {
    expect((new PlexService('orbstack'))->context())->toBe('orbstack')
        ->and((new PlexService)->context())->toBeNull();
});

test('commonsSpec decodes the plex-commons ConfigMap on the constructor context', function (): void {
    Process::fake(['*get configmap plex-commons -n larakube-plex*' => Process::result(output: '{"services":{"postgres":{"enabled":true}}}')]);

    expect((new PlexService('orbstack'))->commonsSpec())->toBe(['services' => ['postgres' => ['enabled' => true]]]);
    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "--context 'orbstack' get configmap plex-commons"));
});

test('commonsSpec is null before the Commons exists', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    expect((new PlexService('orbstack'))->commonsSpec())->toBeNull();
});

test('s3Credentials decodes both keys from plex-admin', function (): void {
    Process::fake([
        '*S3_ACCESS_KEY*' => Process::result(output: base64_encode('access-id')),
        '*S3_SECRET_KEY*' => Process::result(output: base64_encode('secret-key')),
    ]);

    expect((new PlexService('orbstack'))->s3Credentials())->toBe(['access' => 'access-id', 'secret' => 'secret-key']);
});

test('s3Credentials is null when a key is missing', function (): void {
    Process::fake([
        '*S3_ACCESS_KEY*' => Process::result(output: base64_encode('access-id')),
        '*S3_SECRET_KEY*' => Process::result(output: ''),
    ]);

    expect((new PlexService('orbstack'))->s3Credentials())->toBeNull();
});

test('meiliKey decodes the shared master key', function (): void {
    Process::fake(['*MEILI_MASTER_KEY*' => Process::result(output: base64_encode('meili-master'))]);

    expect((new PlexService('orbstack'))->meiliKey())->toBe('meili-master');
});

test('s3Endpoints signs against the public host, and says when there is none', function (): void {
    Process::fake(['*get configmap plex-commons*' => Process::result(output: '{"services":{"seaweedfs":{"enabled":true,"host":"files.example.com"}}}')]);

    expect((new PlexService('orbstack'))->s3Endpoints(StorageDriver::SEAWEEDFS))->toBe([
        'internal' => 'http://seaweedfs.larakube-plex.svc.cluster.local:'.StorageDriver::SEAWEEDFS->port(),
        'public' => 'https://files.example.com',
        'publicHost' => 'files.example.com',
    ]);
});

test('s3Endpoints falls back to the internal endpoint without a public host', function (): void {
    Process::fake(['*' => Process::result(output: '{"services":{"minio":{"enabled":true}}}')]);
    $endpoints = (new PlexService('orbstack'))->s3Endpoints(StorageDriver::MINIO);

    expect($endpoints['public'])->toBe($endpoints['internal'])
        ->and($endpoints['publicHost'])->toBeNull();
});

test('runTenantDatabaseSql pipes the tenant SQL to the engine pod, and skips engines with none', function (): void {
    Process::fake(['*' => Process::result(output: 'CREATE DATABASE')]);
    $plex = new PlexService('orbstack');

    expect($plex->runTenantDatabaseSql(DatabaseDriver::SQLITE, 'shop', 'secret'))->toBeNull()
        ->and($plex->runTenantDatabaseSql(DatabaseDriver::POSTGRESQL, 'shop', 'secret')?->successful())->toBeTrue();

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "--context 'orbstack' exec -i -n 'larakube-plex' deploy/postgres -- sh -c "));
});

test('createBucket runs the backend bucket command in its Commons pod', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    (new PlexService('orbstack'))->createBucket(StorageDriver::SEAWEEDFS, 'shop');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "--context 'orbstack' exec -n 'larakube-plex' deploy/seaweedfs -- sh -c "));
});

test('allocateRedisIndex reuses a tenant slot instead of taking another', function (): void {
    Process::fake([
        '*get configmap plex-registry*' => Process::result(output: '{"tenants":{"notes_main":{"redis_index":4}}}'),
        '*' => Process::result(output: ''),
    ]);

    expect((new PlexService('orbstack'))->allocateRedisIndex('notes_main'))->toBe(4);
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'create configmap plex-registry'));
});

test('allocateRedisIndex takes the lowest free slot and records it', function (): void {
    Process::fake([
        '*get configmap plex-registry*' => Process::result(output: '{"tenants":{"shop":{"redis_index":0}}}'),
        '*' => Process::result(output: ''),
    ]);

    expect((new PlexService('orbstack'))->allocateRedisIndex('notes_main'))->toBe(1);
    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "--context 'orbstack' create configmap plex-registry"));
});

test('commonsEnvValues points a tenant at the Commons services', function (): void {
    $values = (new PlexService)->commonsEnvValues('shop', 'secret', 2, ['postgres', 'redis']);

    expect($values['DB_HOST'])->toBe('postgres.larakube-plex.svc.cluster.local')
        ->and($values['DB_DATABASE'])->toBe('shop')
        ->and($values['REDIS_DB'])->toBe(2);
});
