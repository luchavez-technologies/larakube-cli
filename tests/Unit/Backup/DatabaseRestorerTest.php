<?php

use App\Enums\DatabaseDriver;
use App\Services\Backup\DatabaseRestorer;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('shadowName respects PostgreSQL 63-byte identifier limit', function (): void {
    $long = str_repeat('a', 60);
    $shadow = DatabaseRestorer::shadowName($long, 'lk_restore', 'ab12cd');

    expect(strlen($shadow))->toBeLessThanOrEqual(63)
        ->and($shadow)->toEndWith('__lk_restore_ab12cd');
});

test('filterPostgresDumpStream drops only pre-created extension schemas and extensions while keeping user schemas and tables', function (): void {
    $restorer = new DatabaseRestorer(fn (): array => ['ok' => true, 'output' => '', 'error' => '']);

    $dump = <<<'SQL'
SET statement_timeout = 0;
CREATE SCHEMA extensions;
CREATE SCHEMA eventstore;
CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;
COMMENT ON EXTENSION vector IS 'vector data type';
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA extensions;
CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;
CREATE TABLE eventstore.events (id bigint);
GRANT SELECT ON eventstore.events TO reader_role;
SQL;

    $filtered = $restorer->filterPostgresDumpStream($dump, ['extensions'], ['vector', 'pgcrypto']);

    expect($filtered)
        ->not->toContain('CREATE SCHEMA extensions;')
        ->toContain('CREATE SCHEMA eventstore;')
        ->not->toContain('CREATE EXTENSION IF NOT EXISTS vector')
        ->not->toContain('COMMENT ON EXTENSION vector')
        ->not->toContain('CREATE EXTENSION IF NOT EXISTS pgcrypto')
        ->toContain('CREATE EXTENSION IF NOT EXISTS citext')
        ->toContain('CREATE TABLE eventstore.events (id bigint);')
        ->toContain('GRANT SELECT ON eventstore.events TO reader_role;');
});

test('filterMysqlDumpStream strips database creation and USE statements', function (): void {
    $restorer = new DatabaseRestorer(fn (): array => ['ok' => true, 'output' => '', 'error' => '']);

    $dump = <<<'SQL'
CREATE DATABASE `app_db`;
USE `app_db`;
CREATE TABLE `users` (`id` INT PRIMARY KEY);
INSERT INTO `users` VALUES (1);
SQL;

    $filtered = $restorer->filterMysqlDumpStream($dump);

    expect($filtered)
        ->not->toContain('CREATE DATABASE')
        ->not->toContain('USE `app_db`')
        ->toContain('CREATE TABLE `users`')
        ->toContain('INSERT INTO `users` VALUES (1);');
});

test('failed Postgres replay drops only the shadow DB and never renames or drops the live DB', function (): void {
    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dump = $tmp->path('db.sql');
    file_put_contents($dump, "CREATE TABLE items (id int);\n");

    $executed = [];
    $restorer = new DatabaseRestorer(function (array $cmd, ?string $stdin) use (&$executed): array {
        $executed[] = ['cmd' => $cmd, 'stdin' => $stdin];
        $joined = implode(' ', $cmd);

        if ($stdin !== null && str_contains($joined, '--single-transaction')) {
            return ['ok' => false, 'output' => '', 'error' => 'syntax error at or near "BAD"'];
        }

        return ['ok' => true, 'output' => '', 'error' => ''];
    });

    $ok = $restorer->restore(DatabaseDriver::POSTGRESQL, 'app_prod', $dump);

    expect($ok)->toBeFalse()
        ->and($restorer->lastError())->toContain('shadow DB');

    $allSql = implode("\n", array_map(fn (array $e): string => (string) ($e['stdin'] ?? ''), $executed));

    expect($allSql)
        ->not->toContain('ALTER DATABASE "app_prod" RENAME TO')
        ->not->toContain('DROP DATABASE IF EXISTS "app_prod"');

    $dropCount = substr_count($allSql, 'DROP DATABASE IF EXISTS "app_prod__lk_restore_');
    expect($dropCount)->toBeGreaterThanOrEqual(2);
});

test('failed MySQL import drops shadow DBs and never drops or renames live tables', function (): void {
    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dump = $tmp->path('db.sql');
    file_put_contents($dump, "CREATE TABLE broken (id int);\n");

    $executed = [];
    $restorer = new DatabaseRestorer(function (array $cmd, ?string $stdin) use (&$executed): array {
        $executed[] = ['cmd' => $cmd, 'stdin' => $stdin];
        $joined = implode(' ', $cmd);

        if (str_contains($joined, 'mysqldump')) {
            return ['ok' => true, 'output' => "CREATE TABLE live_tbl (id int);\n", 'error' => ''];
        }
        if ($stdin !== null && str_contains($stdin, 'DEFAULT_CHARACTER_SET_NAME')) {
            return ['ok' => true, 'output' => "utf8mb4\tutf8mb4_unicode_ci\n", 'error' => ''];
        }
        if ($stdin !== null && str_contains($stdin, 'CREATE TABLE broken')) {
            return ['ok' => false, 'output' => '', 'error' => 'ERROR 1064'];
        }

        return ['ok' => true, 'output' => '', 'error' => ''];
    });

    $ok = $restorer->restore(DatabaseDriver::MYSQL, 'app_prod', $dump);

    expect($ok)->toBeFalse()
        ->and($restorer->lastError())->toContain('Failed importing dump into shadow DB');

    $allSql = implode("\n", array_map(fn (array $e): string => (string) ($e['stdin'] ?? ''), $executed));

    expect($allSql)
        ->not->toContain('RENAME TABLE')
        ->not->toContain('DROP DATABASE IF EXISTS `app_prod`')
        ->toContain('DROP DATABASE IF EXISTS `app_prod__lk_restore_');
});
