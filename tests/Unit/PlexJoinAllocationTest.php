<?php

/**
 * Pure-logic tests for the tenant-join allocation helpers — identifier
 * sanitization, Redis-index allocation, .env merging, the connection values,
 * the idempotent Postgres SQL, and registry transforms. The kubectl/psql I/O in
 * PlexJoinCommand belongs in a cluster smoke test, not here.
 */

use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;

function plexJoin(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('tenant identifier sanitizes app names to safe SQL identifiers', function () {
    $p = plexJoin();

    expect($p->plexTenantIdentifier('app-one'))->toBe('app_one')
        ->and($p->plexTenantIdentifier('My App!'))->toBe('my_app')
        ->and($p->plexTenantIdentifier('  Drift.Labs  '))->toBe('drift_labs')
        ->and($p->plexTenantIdentifier('1app'))->toBe('app_1app')   // must start with a letter
        ->and($p->plexTenantIdentifier(''))->toBe('app_');
});

test('redis index allocation picks the lowest free slot, or null when full', function () {
    $p = plexJoin();

    expect($p->allocateRedisDbIndex([]))->toBe(0)
        ->and($p->allocateRedisDbIndex([0, 1, 2]))->toBe(3)
        ->and($p->allocateRedisDbIndex([0, 2]))->toBe(1)            // fills the gap
        ->and($p->allocateRedisDbIndex(range(0, 15)))->toBeNull();  // 16 logical DBs, full
});

test('applyEnvValues replaces in place (even commented) and appends new keys', function () {
    $p = plexJoin();

    $content = "APP_NAME=Demo\n# DB_HOST=old\nDB_PASSWORD=keepme";
    $out = $p->applyEnvValues($content, ['DB_HOST' => 'postgres.larakube-plex.svc.cluster.local', 'REDIS_DB' => 3]);

    expect($out)
        ->toContain('APP_NAME=Demo')                                          // untouched
        ->toContain('DB_HOST=postgres.larakube-plex.svc.cluster.local')     // uncommented + replaced
        ->not->toContain('# DB_HOST=old')
        ->toContain('REDIS_DB=3')                                             // appended
        ->toContain('DB_PASSWORD=keepme');
});

test('commonsEnvValues emits only the requested services', function () {
    $p = plexJoin();

    $both = $p->commonsEnvValues('app_one', 'secret', 5, ['postgres', 'redis']);
    expect($both['DB_HOST'])->toBe('postgres.larakube-plex.svc.cluster.local')
        ->and($both['DB_DATABASE'])->toBe('app_one')
        ->and($both['DB_USERNAME'])->toBe('app_one')
        ->and($both['DB_PASSWORD'])->toBe('secret')
        ->and($both['REDIS_HOST'])->toBe('redis.larakube-plex.svc.cluster.local')
        ->and($both['REDIS_DB'])->toBe(5);

    $redisOnly = $p->commonsEnvValues('app_one', 'secret', 2, ['redis']);
    expect($redisOnly)->not->toHaveKey('DB_HOST')
        ->and($redisOnly['REDIS_DB'])->toBe(2);
});

test('commonsEnvValues wires S3 generically from the tenant backend + per-tenant bucket', function () {
    $p = plexJoin();

    // The caller passes the tenant's OWN backend (service + port) — no hardcoded service.
    // The bucket is the DNS-safe form of the tenant id (app_four → app-four).
    $s3 = $p->commonsEnvValues('app_four', 'pw', null, ['seaweedfs'], ['service' => 'seaweedfs', 'port' => 8333, 'access' => 'larakube', 'secret' => 'sk']);
    expect($s3['FILESYSTEM_DISK'])->toBe('s3')
        ->and($s3['AWS_BUCKET'])->toBe('app-four')                                                  // per-tenant bucket, DNS-safe
        ->and($s3['AWS_ENDPOINT'])->toBe('http://seaweedfs.larakube-plex.svc.cluster.local:8333') // its backend's endpoint
        ->and($s3['AWS_ACCESS_KEY_ID'])->toBe('larakube')
        ->and($s3['AWS_SECRET_ACCESS_KEY'])->toBe('sk')
        ->and($s3['AWS_USE_PATH_STYLE_ENDPOINT'])->toBe('true')
        ->and($s3)->not->toHaveKey('AWS_URL');                                                      // no public host → no public URL

    // A different backend lands on its OWN endpoint — generic, not collapsed onto seaweedfs.
    $minio = $p->commonsEnvValues('app_four', 'pw', null, ['minio'], ['service' => 'minio', 'port' => 9000, 'access' => 'k', 'secret' => 's']);
    expect($minio['AWS_ENDPOINT'])->toBe('http://minio.larakube-plex.svc.cluster.local:9000');

    // A configured public host → AWS_URL (path-style host/bucket, DNS-safe bucket).
    $withHost = $p->commonsEnvValues('app_four', 'pw', null, ['seaweedfs'], ['service' => 'seaweedfs', 'port' => 8333, 'access' => 'k', 'secret' => 's', 'host' => 's3.example.com']);
    expect($withHost['AWS_URL'])->toBe('https://s3.example.com/app-four');

    // No S3 details → no S3 env.
    expect($p->commonsEnvValues('app_four', 'pw', null, ['postgres']))->not->toHaveKey('AWS_BUCKET')
        ->and($p->commonsEnvValues('app_four', 'pw', null, ['seaweedfs'], null))->not->toHaveKey('AWS_BUCKET');
});

test('commonsEnvValues repoints MEILISEARCH_* at the Commons when search joins', function () {
    $p = plexJoin();

    // Joining Meilisearch must move BOTH the host and the key off the tenant's
    // own pod — the overlay deletes that Deployment, so a stale host is a
    // guaranteed connection failure once the app redeploys.
    $search = ['service' => 'meilisearch', 'port' => 7700, 'key' => 'commons-master-key'];
    $joined = $p->commonsEnvValues('app_five', 'pw', null, ['meilisearch'], null, $search);

    expect($joined['MEILISEARCH_HOST'])->toBe('http://meilisearch.larakube-plex.svc.cluster.local:7700')
        ->and($joined['MEILISEARCH_KEY'])->toBe('commons-master-key');

    // Kept self-hosted (not in $services, or no key resolved) → leave its env alone.
    expect($p->commonsEnvValues('app_five', 'pw', null, ['postgres'], null, $search))->not->toHaveKey('MEILISEARCH_HOST')
        ->and($p->commonsEnvValues('app_five', 'pw', null, ['meilisearch'], null, null))->not->toHaveKey('MEILISEARCH_HOST');
});

test('postgres tenant SQL is idempotent and escapes the password', function () {
    $p = plexJoin();

    $sql = $p->buildPostgresTenantSql('app_one', 'app_one', "pa'ss");

    expect($sql)
        ->toContain("WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'app_one')")  // create-db guard
        ->toContain('IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = \'app_one\')')      // create-role guard
        ->toContain('ALTER DATABASE "app_one" OWNER TO "app_one"')                           // tenant owns its db
        ->toContain('\connect "app_one"')                                                    // switch into the tenant db
        ->toContain('ALTER SCHEMA public OWNER TO "app_one"')                                // …so migrations can create tables
        ->toContain('GRANT ALL PRIVILEGES ON DATABASE "app_one" TO "app_one"')
        ->toContain("PASSWORD 'pa''ss'");                                                    // '' escaping
});

test('drop tenant SQL terminates connections then drops the database and role', function () {
    $p = plexJoin();

    $sql = $p->buildDropTenantSql('app_one', 'app_one');

    expect($sql)
        ->toContain('pg_terminate_backend(pid)')                                  // kill live sessions first
        ->toContain("WHERE datname = 'app_one' AND pid <> pg_backend_pid()")      // …but not ourselves
        ->toContain('DROP DATABASE IF EXISTS "app_one"')                          // idempotent db drop
        ->toContain('DROP ROLE IF EXISTS "app_one"');                             // then the role
});

test('registry transforms add, remove, and report used redis indexes', function () {
    $p = plexJoin();

    $r = $p->registryAdd([], 'app_one', ['db' => 'app_one', 'redis_index' => 0]);
    $r = $p->registryAdd($r, 'app_two', ['db' => 'app_two', 'redis_index' => 1]);

    expect($p->registryUsedRedisIndexes($r))->toBe([0, 1]);

    $r = $p->registryRemove($r, 'app_one');
    expect($r['tenants'])->toHaveKey('app_two')
        ->and($r['tenants'])->not->toHaveKey('app_one')
        ->and($p->registryUsedRedisIndexes($r))->toBe([1]);
});

test('commonsServiceTenants reports who uses a service (the plex:remove guard)', function () {
    $p = plexJoin();
    $registry = ['tenants' => [
        'app_one' => ['db' => 'app_one', 'redis_index' => null],                                  // legacy: no db_service → Postgres
        'app_four' => ['db' => 'app_four', 'redis_index' => 0],
        'app_five' => ['db' => 'app_five', 's3_service' => 'seaweedfs', 's3_bucket' => 'app_five'],
        'app_six' => ['db' => 'app_six', 'db_service' => 'mysql', 'redis_index' => null],          // explicit MySQL tenant
    ]];

    expect($p->commonsServiceTenants($registry, 'redis'))->toBe(['app_four'])            // only the one with an index
        ->and($p->commonsServiceTenants($registry, 'seaweedfs'))->toBe(['app_five'])     // by recorded s3_service
        ->and($p->commonsServiceTenants($registry, 'minio'))->toBe([])                   // no minio tenant → safe to remove
        ->and($p->commonsServiceTenants($registry, 'mysql'))->toBe(['app_six'])          // only the explicit MySQL tenant
        ->and($p->commonsServiceTenants($registry, 'postgres'))->toBe(['app_one', 'app_four', 'app_five']); // legacy rows = Postgres, NOT app_six
});

test('commonsEnvValues points DB_* at the tenant engine service (postgres/mysql/mariadb)', function () {
    $p = plexJoin();

    $pg = $p->commonsEnvValues('app_one', 'secret', null, ['postgres']);
    expect($pg['DB_HOST'])->toBe('postgres.larakube-plex.svc.cluster.local')
        ->and($pg['DB_PORT'])->toBe(5432);

    $my = $p->commonsEnvValues('app_one', 'secret', null, ['mysql']);
    expect($my['DB_HOST'])->toBe('mysql.larakube-plex.svc.cluster.local')
        ->and($my['DB_PORT'])->toBe(3306)
        ->and($my['DB_DATABASE'])->toBe('app_one')
        ->and($my['DB_USERNAME'])->toBe('app_one')
        ->and($my['DB_PASSWORD'])->toBe('secret');

    expect($p->commonsEnvValues('app_one', 'secret', null, ['mariadb'])['DB_HOST'])
        ->toBe('mariadb.larakube-plex.svc.cluster.local');
});

test('MySQL/MariaDB Commons tenant SQL scopes a db + user and escapes the password', function () {
    $sql = DatabaseDriver::MYSQL->commonsTenantSql('app_one', 'app_one', "pa'ss");

    expect($sql)
        ->toContain('CREATE DATABASE IF NOT EXISTS `app_one`')
        ->toContain("CREATE USER IF NOT EXISTS 'app_one'@'%' IDENTIFIED BY 'pa\\'ss'")  // backslash-escaped quote
        ->toContain('GRANT ALL PRIVILEGES ON `app_one`.* TO \'app_one\'@\'%\'')
        ->toContain('FLUSH PRIVILEGES;');

    expect(DatabaseDriver::MARIADB->commonsDropSql('app_one', 'app_one'))
        ->toContain('DROP DATABASE IF EXISTS `app_one`')
        ->toContain("DROP USER IF EXISTS 'app_one'@'%'");

    // Non-relational engines aren't Commons DB backends.
    expect(DatabaseDriver::SQLITE->commonsTenantSql('a', 'a', 'a'))->toBeNull();
});

test('plexBucketName makes a DNS-safe bucket from a tenant id', function () {
    $p = plexJoin();

    expect($p->plexBucketName('app_five'))->toBe('app-five')          // underscores → hyphens (S3/MinIO reject _)
        ->and($p->plexBucketName('My_App__1'))->toBe('my-app-1')      // collapse + lowercase
        ->and($p->plexBucketName('_edge_'))->toBe('edge')             // trim leading/trailing hyphens
        ->and($p->plexBucketName('ab'))->toBe('lk-ab');              // pad to the 3-char S3 minimum
});

test('Commons bucket commands are per-backend (SeaweedFS weed shell, MinIO mc)', function () {
    expect(StorageDriver::SEAWEEDFS->commonsBucketCreateCommand('app-five'))
        ->toContain('s3.bucket.create -name app-five')
        ->toContain('weed shell');

    $mc = StorageDriver::MINIO->commonsBucketCreateCommand('app-five');
    expect($mc)
        ->toContain('mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD"')  // pod expands creds
        ->toContain('mc mb --ignore-existing local/app-five')
        ->toContain('MC_CONFIG_DIR=/tmp/mc');

    expect(StorageDriver::MINIO->commonsBucketDeleteCommand('app-five'))->toContain('mc rb --force local/app-five')
        ->and(StorageDriver::SEAWEEDFS->commonsBucketDeleteCommand('app-five'))->toContain('s3.bucket.delete -name app-five');
});
