<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;

test('database driver has correct labels', function () {
    expect(DatabaseDriver::MYSQL->getLabel())->toBe('MySQL')
        ->and(DatabaseDriver::MARIADB->getLabel())->toBe('MariaDB')
        ->and(DatabaseDriver::POSTGRESQL->getLabel())->toBe('PostgreSQL')
        ->and(DatabaseDriver::MONGODB->getLabel())->toBe('MongoDB')
        ->and(DatabaseDriver::SQLITE->getLabel())->toBe('SQLite (Local File)');
});

test('database driver has correct ports', function () {
    expect(DatabaseDriver::MYSQL->dbPort())->toBe(3306)
        ->and(DatabaseDriver::MARIADB->dbPort())->toBe(3306)
        ->and(DatabaseDriver::POSTGRESQL->dbPort())->toBe(5432)
        ->and(DatabaseDriver::MONGODB->dbPort())->toBe(27017)
        ->and(DatabaseDriver::SQLITE->dbPort())->toBe(0);
});

test('database driver has correct connections', function () {
    expect(DatabaseDriver::MYSQL->dbConnection())->toBe('mysql')
        ->and(DatabaseDriver::POSTGRESQL->dbConnection())->toBe('pgsql')
        ->and(DatabaseDriver::MONGODB->dbConnection())->toBe('mongodb')
        ->and(DatabaseDriver::SQLITE->dbConnection())->toBe('sqlite');
});

test('sqlite is hidden when using frankenphp', function () {
    $config = ConfigData::from(['serverVariation' => 'frankenphp']);
    expect(DatabaseDriver::SQLITE->isHidden($config))->toBeTrue();

    $config = ConfigData::from(['serverVariation' => 'fpm-nginx']);
    expect(DatabaseDriver::SQLITE->isHidden($config))->toBeFalse();
});

test('database driver select options are valid', function () {
    $options = DatabaseDriver::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('mysql', 'MySQL');
});

test('test database provision command is null for drivers without auto-provisioning', function () {
    expect(DatabaseDriver::SQLITE->getTestDatabaseProvisionCommand('app_testing'))->toBeNull()
        ->and(DatabaseDriver::MONGODB->getTestDatabaseProvisionCommand('app_testing'))->toBeNull();
});

test('mysql test database provision command uses CREATE DATABASE IF NOT EXISTS', function () {
    $cmd = DatabaseDriver::MYSQL->getTestDatabaseProvisionCommand('demo_testing');

    expect($cmd)->toContain('CREATE DATABASE IF NOT EXISTS')
        ->and($cmd)->toContain('`demo_testing`')
        ->and($cmd)->toContain('$MYSQL_ROOT_PASSWORD');
});

test('mariadb uses the same provisioning command as mysql', function () {
    $mariadbCmd = DatabaseDriver::MARIADB->getTestDatabaseProvisionCommand('app_testing');
    $mysqlCmd = DatabaseDriver::MYSQL->getTestDatabaseProvisionCommand('app_testing');

    expect($mariadbCmd)->toBe($mysqlCmd);
});

test('postgres test database provision command checks pg_database before createdb', function () {
    $cmd = DatabaseDriver::POSTGRESQL->getTestDatabaseProvisionCommand('demo_testing');

    expect($cmd)->toContain("SELECT 1 FROM pg_database WHERE datname='demo_testing'")
        ->and($cmd)->toContain('createdb -U "$POSTGRES_USER" "demo_testing"')
        ->and($cmd)->toContain('PGPASSWORD="$POSTGRES_PASSWORD"')
        ->and($cmd)->toContain('-d "$POSTGRES_DB"')
        ->and($cmd)->toContain('||');
});

test('postgres Commons restore connects as the tenant role, not admin', function () {
    // Regression guard: postgresCommonsCreateSql() makes the tenant own its
    // database + public schema, but that ownership doesn't extend to objects
    // a DIFFERENT role's CREATE TABLE (from the restored dump) produces
    // inside it — restoring as "postgres" left every table owned by the
    // admin superuser, so the tenant's own app connection got "permission
    // denied" on its own tables.
    $cmd = DatabaseDriver::POSTGRESQL->commonsRestoreCommand('seahorse_local', 'tmp-pw-123');

    expect($cmd)->toContain("-U 'seahorse_local'")
        ->not->toContain('-U postgres')
        ->and($cmd)->toContain("PGPASSWORD='tmp-pw-123'")
        ->and($cmd)->toContain('-h 127.0.0.1') // force TCP/password auth, not local-socket peer auth
        ->and($cmd)->toContain("-d 'seahorse_local'");
});

test('mysql/mariadb Commons restore is unaffected by the password param (grant-based, no ownership gap)', function () {
    $mysql = DatabaseDriver::MYSQL->commonsRestoreCommand('demo', 'unused');
    $mariadb = DatabaseDriver::MARIADB->commonsRestoreCommand('demo', 'unused');

    expect($mysql)->toContain('-uroot')->not->toContain('unused')
        ->and($mariadb)->toContain('-uroot')->not->toContain('unused');
});
