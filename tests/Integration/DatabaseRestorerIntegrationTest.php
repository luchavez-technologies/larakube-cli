<?php

use App\Enums\DatabaseDriver;
use App\Services\Backup\DatabaseRestorer;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Process\Process;

function runDbDocker(string $docker, array $args, int $timeout = 120): Process
{
    $process = new Process([$docker, ...$args]);
    $process->setTimeout($timeout);
    $process->run();

    return $process;
}

test('real Postgres shadow-DB restore handles pgvector, custom extension schema, multi-schema, grants, and rollback on failure', function (): void {
    $docker = $this->requireDocker();

    $this->withoutHostBinaryStubs(function () use ($docker): void {
        $cid = 'lk-test-pg-restore-'.getmypid().'-'.bin2hex(random_bytes(3));
        $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();

        try {
            $start = runDbDocker($docker, [
                'run', '-d', '--rm',
                '--name', $cid,
                '-e', 'POSTGRES_PASSWORD=postgres',
                'pgvector/pgvector:0.8.7-pg17-trixie',
            ]);
            expect($start->isSuccessful())->toBeTrue($start->getErrorOutput());

            $ready = false;
            for ($i = 0; $i < 60; $i++) {
                if (runDbDocker($docker, ['exec', $cid, 'psql', '-h', '127.0.0.1', '-U', 'postgres', '-d', 'postgres', '-c', 'SELECT 1'])->isSuccessful()) {
                    $ready = true;
                    break;
                }
                usleep(250_000);
            }
            expect($ready)->toBeTrue('Postgres container did not become ready in time.');

            $psql = fn (string $db, string $sql): Process => (function () use ($docker, $cid, $db, $sql): Process {
                $p = new Process([$docker, 'exec', '-i', $cid, 'psql', '-U', 'postgres', '-v', 'ON_ERROR_STOP=1', '-tAc', $sql, '-d', $db]);
                $p->setTimeout(60);
                $p->run();

                return $p;
            })();

            $execSql = function (string $db, string $sql) use ($docker, $cid): Process {
                $p = new Process([$docker, 'exec', '-i', $cid, 'psql', '-U', 'postgres', '-v', 'ON_ERROR_STOP=1', '-d', $db]);
                $p->setInput($sql);
                $p->setTimeout(60);
                $p->run();

                return $p;
            };

            // Create non-superuser owner role `tenant_a`, non-owner `reader_role`, and target DB.
            expect($execSql('postgres', <<<'SQL'
CREATE ROLE tenant_a LOGIN PASSWORD 'secret_a';
CREATE ROLE reader_role NOLOGIN;
CREATE DATABASE app_tenant OWNER tenant_a;
SQL)->isSuccessful())->toBeTrue();

            // Populate pgvector in public, pgcrypto in custom `extensions` schema, multi-schema `eventstore`, and grants.
            expect($execSql('app_tenant', <<<'SQL'
CREATE SCHEMA extensions AUTHORIZATION tenant_a;
CREATE EXTENSION vector WITH SCHEMA public;
CREATE EXTENSION pgcrypto WITH SCHEMA extensions;
SET ROLE tenant_a;
CREATE SCHEMA eventstore;
CREATE TABLE public.embeddings (id serial PRIMARY KEY, vec public.vector(3), digest text);
INSERT INTO public.embeddings (vec, digest) VALUES ('[1,2,3]', encode(extensions.digest('ok', 'sha256'), 'hex'));
CREATE TABLE eventstore.events (id serial PRIMARY KEY, kind text);
INSERT INTO eventstore.events (kind) VALUES ('bootstrapped');
RESET ROLE;
GRANT USAGE ON SCHEMA eventstore TO reader_role;
GRANT SELECT ON ALL TABLES IN SCHEMA eventstore TO reader_role;
SQL)->isSuccessful())->toBeTrue();

            // Take a real pg_dump --no-owner exactly as BackupRunCommand does.
            $dumpProc = runDbDocker($docker, ['exec', $cid, 'pg_dump', '-U', 'postgres', '--no-owner', 'app_tenant']);
            expect($dumpProc->isSuccessful())->toBeTrue($dumpProc->getErrorOutput());
            $dumpFile = $tmp->path('app_tenant.sql.gz');
            file_put_contents($dumpFile, gzencode($dumpProc->getOutput()));

            // Mutate live DB to prove restore overwrites cleanly over an existing populated DB.
            expect($execSql('app_tenant', <<<'SQL'
SET ROLE tenant_a;
INSERT INTO public.embeddings (vec, digest) VALUES ('[9,9,9]', 'stale');
INSERT INTO eventstore.events (kind) VALUES ('should-disappear');
CREATE TABLE public.stale_table (x int);
RESET ROLE;
SQL)->isSuccessful())->toBeTrue();

            $restorer = DatabaseRestorer::forDockerContainer($docker, $cid);
            $ok = $restorer->restore(DatabaseDriver::POSTGRESQL, 'app_tenant', $dumpFile, [
                'ownerRole' => 'tenant_a',
                'grants' => [
                    'GRANT USAGE ON SCHEMA eventstore TO reader_role;',
                    'GRANT SELECT ON ALL TABLES IN SCHEMA eventstore TO reader_role;',
                ],
            ]);

            expect($ok)->toBeTrue((string) $restorer->lastError());

            $embedCount = trim($psql('app_tenant', 'SELECT count(*) FROM public.embeddings;')->getOutput());
            $eventKind = trim($psql('app_tenant', 'SELECT kind FROM eventstore.events;')->getOutput());
            $staleExists = trim($psql('app_tenant', "SELECT to_regclass('public.stale_table') IS NOT NULL;")->getOutput());
            $nonOwned = trim($psql('app_tenant', "SELECT count(*) FROM pg_tables WHERE schemaname IN ('public', 'eventstore') AND tableowner <> 'tenant_a';")->getOutput());
            $readerPriv = trim($psql('app_tenant', "SELECT has_table_privilege('reader_role', 'eventstore.events', 'SELECT');")->getOutput());

            expect($embedCount)->toBe('1')
                ->and($eventKind)->toBe('bootstrapped')
                ->and($staleExists)->toBe('f')
                ->and($nonOwned)->toBe('0')
                ->and($readerPriv)->toBe('t');

            // Corrupt dump must fail and leave live `app_tenant` 100% untouched.
            $badDump = $tmp->path('bad.sql');
            file_put_contents($badDump, "CREATE TABLE public.partial_fail (id int);\nTHIS IS INVALID SQL;\n");

            expect($restorer->restore(DatabaseDriver::POSTGRESQL, 'app_tenant', $badDump, ['ownerRole' => 'tenant_a']))->toBeFalse()
                ->and(trim($psql('app_tenant', 'SELECT count(*) FROM public.embeddings;')->getOutput()))->toBe('1')
                ->and(trim($psql('app_tenant', 'SELECT kind FROM eventstore.events;')->getOutput()))->toBe('bootstrapped');
        } finally {
            runDbDocker($docker, ['rm', '-f', $cid]);
        }
    });
});

test('real MySQL shadow-DB swap removes ghost tables, preserves charset/views/routines/triggers, and survives failure', function (): void {
    $docker = $this->requireDocker();

    $this->withoutHostBinaryStubs(function () use ($docker): void {
        $cid = 'lk-test-mysql-restore-'.getmypid().'-'.bin2hex(random_bytes(3));
        $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();

        try {
            $start = runDbDocker($docker, [
                'run', '-d', '--rm',
                '--name', $cid,
                '-e', 'MYSQL_ROOT_PASSWORD=rootpw',
                'mysql:8.4',
            ]);
            expect($start->isSuccessful())->toBeTrue($start->getErrorOutput());

            $mysqlExec = function (?string $db, string $sql) use ($docker, $cid): Process {
                $args = [$docker, 'exec', '-i', $cid, 'mysql', '-uroot', '-prootpw', '-N', '-B'];
                if ($db !== null) {
                    $args[] = $db;
                }
                $p = new Process($args);
                $p->setInput($sql);
                $p->setTimeout(60);
                $p->run();

                return $p;
            };

            $ready = false;
            for ($i = 0; $i < 80; $i++) {
                if ($mysqlExec(null, 'SELECT 1;')->isSuccessful()) {
                    $ready = true;
                    break;
                }
                usleep(500_000);
            }
            expect($ready)->toBeTrue('MySQL container did not become ready in time.')
                ->and($mysqlExec(null, <<<'SQL'
CREATE DATABASE app_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE app_mysql;
CREATE TABLE items (id INT PRIMARY KEY, title VARCHAR(64));
CREATE TABLE audit_log (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT);
INSERT INTO items VALUES (1, 'original');
CREATE VIEW item_titles AS SELECT title FROM items;
DELIMITER //
CREATE TRIGGER items_after_insert AFTER INSERT ON items FOR EACH ROW
BEGIN
    INSERT INTO audit_log (item_id) VALUES (NEW.id);
END//
CREATE FUNCTION item_count() RETURNS INT DETERMINISTIC
BEGIN
    DECLARE c INT;
    SELECT COUNT(*) INTO c FROM items;
    RETURN c;
END//
DELIMITER ;
SQL)->isSuccessful())->toBeTrue();

            $dumpProc = runDbDocker($docker, ['exec', $cid, 'mysqldump', '-uroot', '-prootpw', '--routines', '--triggers', '--single-transaction', 'app_mysql']);
            expect($dumpProc->isSuccessful())->toBeTrue($dumpProc->getErrorOutput());
            $dumpFile = $tmp->path('app_mysql.sql.gz');
            file_put_contents($dumpFile, gzencode($dumpProc->getOutput()));

            // Add stale ghost table and mutate live row.
            expect($mysqlExec('app_mysql', <<<'SQL'
CREATE TABLE ghost_table (id INT);
INSERT INTO items VALUES (2, 'stale-row');
SQL)->isSuccessful())->toBeTrue();

            $restorer = DatabaseRestorer::forDockerContainer($docker, $cid);
            $safetyDump = $tmp->path('safety.sql');
            $ok = $restorer->restore(DatabaseDriver::MYSQL, 'app_mysql', $dumpFile, [
                'safetyDumpPath' => $safetyDump,
            ]);

            expect($ok)->toBeTrue((string) $restorer->lastError())
                ->and($safetyDump)->toBeFile()
                ->and(filesize($safetyDump))->toBeGreaterThan(0);

            $ghostCheck = trim($mysqlExec(null, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='app_mysql' AND TABLE_NAME='ghost_table';")->getOutput());
            $itemCount = trim($mysqlExec('app_mysql', 'SELECT item_count();')->getOutput());
            $viewTitle = trim($mysqlExec('app_mysql', 'SELECT title FROM item_titles;')->getOutput());
            $charset = trim($mysqlExec(null, "SELECT CONCAT(DEFAULT_CHARACTER_SET_NAME, ':', DEFAULT_COLLATION_NAME) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='app_mysql';")->getOutput());

            expect($ghostCheck)->toBe('0')
                ->and($itemCount)->toBe('1')
                ->and($viewTitle)->toBe('original')
                ->and($charset)->toBe('utf8mb4:utf8mb4_unicode_ci');

            // Verify trigger survived swap.
            expect($mysqlExec('app_mysql', "INSERT INTO items VALUES (3, 'post-restore');")->isSuccessful())->toBeTrue();
            expect(trim($mysqlExec('app_mysql', 'SELECT COUNT(*) FROM audit_log WHERE item_id = 3;')->getOutput()))->toBe('1');

            // Corrupt dump must fail and leave live DB intact.
            $badDump = $tmp->path('bad-mysql.sql');
            file_put_contents($badDump, "CREATE TABLE ok_tbl (id INT);\nINVALID MYSQL STATEMENT;\n");

            expect($restorer->restore(DatabaseDriver::MYSQL, 'app_mysql', $badDump))->toBeFalse()
                ->and(trim($mysqlExec('app_mysql', 'SELECT COUNT(*) FROM items;')->getOutput()))->toBe('2');
        } finally {
            runDbDocker($docker, ['rm', '-f', $cid]);
        }
    });
});
