<?php

namespace App\Services\Backup;

use App\Enums\DatabaseDriver;
use App\Services\Kubectl;
use Closure;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Restores a database dump safely into an isolated shadow database, verifies
 * ownership/counts/schema objects, and atomically swaps it into place — leaving
 * the live database untouched on any failure.
 */
final class DatabaseRestorer
{
    private ?string $lastError = null;

    /**
     * @param  Closure(list<string>, ?string): array{ok: bool, output: string, error: string}  $runner
     */
    public function __construct(
        private readonly Closure $runner,
    ) {}

    public static function forPod(string $kubectl, string $namespace, string $target, ?string $container = null): self
    {
        return new self(function (array $command, ?string $stdin = null) use ($kubectl, $namespace, $target, $container): array {
            $args = ['exec'];
            if ($stdin !== null) {
                $args[] = '-i';
            }
            $args[] = '-n';
            $args[] = $namespace;
            $args[] = $target;
            if ($container !== null && $container !== '') {
                $args[] = '-c';
                $args[] = $container;
            }
            $args[] = '--';
            foreach ($command as $part) {
                $args[] = $part;
            }

            $cmd = rtrim($kubectl).' '.implode(' ', array_map(Kubectl::arg(...), $args));
            $pending = Process::timeout(1800);
            $result = ($stdin !== null ? $pending->input($stdin) : $pending)->run($cmd);

            return [
                'ok' => $result->successful(),
                'output' => $result->output(),
                'error' => $result->errorOutput(),
            ];
        });
    }

    public static function forDockerContainer(string $docker, string $containerId): self
    {
        return new self(function (array $command, ?string $stdin = null) use ($docker, $containerId): array {
            $args = [$docker, 'exec'];
            if ($stdin !== null) {
                $args[] = '-i';
            }
            $args[] = $containerId;
            foreach ($command as $part) {
                $args[] = $part;
            }

            $process = new SymfonyProcess($args);
            $process->setTimeout(1800);
            if ($stdin !== null) {
                $process->setInput($stdin);
            }
            $process->run();

            return [
                'ok' => $process->isSuccessful(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ];
        });
    }

    public static function shadowName(string $db, string $tag, string $suffix): string
    {
        $tail = "__{$tag}_{$suffix}";
        $maxPrefix = max(1, 63 - strlen($tail));

        return substr($db, 0, $maxPrefix).$tail;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array{
     *   ownerRole?: string,
     *   extensionSchemas?: list<string>,
     *   extensions?: list<array{name: string, schema?: string}>,
     *   grants?: list<string>,
     *   charset?: string,
     *   collation?: string,
     *   safetyDumpPath?: string
     * }  $options
     */
    public function restore(DatabaseDriver $driver, string $database, string $dumpPath, array $options = []): bool
    {
        $this->lastError = null;

        return match ($driver) {
            DatabaseDriver::POSTGRESQL => $this->restorePostgres($database, $dumpPath, $options),
            DatabaseDriver::MYSQL, DatabaseDriver::MARIADB => $this->restoreMysqlFamily($driver, $database, $dumpPath, $options),
            default => $this->fail("Unsupported database driver for restore: {$driver->value}"),
        };
    }

    /**
     * @param  array{
     *   ownerRole?: string,
     *   extensionSchemas?: list<string>,
     *   extensions?: list<array{name: string, schema?: string}>,
     *   grants?: list<string>
     * }  $options
     */
    public function restorePostgres(string $database, string $dumpPath, array $options = []): bool
    {
        $sql = $this->readDump($dumpPath);
        if ($sql === null) {
            return $this->fail("Dump file is missing or empty: {$dumpPath}");
        }

        $ownerRole = trim((string) ($options['ownerRole'] ?? '')) ?: $database;
        $suffix = bin2hex(random_bytes(3));
        $shadow = self::shadowName($database, 'lk_restore', $suffix);
        $old = self::shadowName($database, 'lk_old', $suffix);

        [$detectedSchemas, $detectedExtensions] = $this->detectPostgresExtensions($sql);

        $extensions = [];
        foreach (array_merge($options['extensions'] ?? [], $detectedExtensions) as $ext) {
            $name = trim((string) ($ext['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $schema = trim((string) ($ext['schema'] ?? 'public')) ?: 'public';
            $extensions[$name] = ['name' => $name, 'schema' => $schema];
        }

        $extensionSchemas = array_values(array_unique(array_filter(
            array_merge(
                $options['extensionSchemas'] ?? [],
                $detectedSchemas,
                array_map(fn (array $e): string => $e['schema'], array_values($extensions)),
            ),
            fn (string $s): bool => $s !== '' && $s !== 'public' && $s !== 'pg_catalog',
        )));

        $dropShadow = fn () => $this->runPsql('postgres', 'DROP DATABASE IF EXISTS '.$this->pgIdent($shadow).' WITH (FORCE);');

        // 1. Create shadow database owned by the tenant/target role.
        $createDbSql = implode("\n", [
            'DROP DATABASE IF EXISTS '.$this->pgIdent($shadow).' WITH (FORCE);',
            'CREATE DATABASE '.$this->pgIdent($shadow).' OWNER '.$this->pgIdent($ownerRole).';',
        ]);
        $res = $this->runPsql('postgres', $createDbSql);
        if (! $res['ok']) {
            return $this->fail("Failed to create Postgres shadow DB {$shadow}: {$res['error']}");
        }

        // 2. Pre-create extension-hosting schemas & extensions as superuser before SET ROLE.
        $prepStatements = [
            'ALTER SCHEMA public OWNER TO '.$this->pgIdent($ownerRole).';',
        ];
        foreach ($extensionSchemas as $schema) {
            $prepStatements[] = 'CREATE SCHEMA IF NOT EXISTS '.$this->pgIdent($schema).' AUTHORIZATION '.$this->pgIdent($ownerRole).';';
        }
        foreach ($extensions as $ext) {
            $prepStatements[] = 'CREATE EXTENSION IF NOT EXISTS '.$this->pgIdent($ext['name']).' WITH SCHEMA '.$this->pgIdent($ext['schema']).';';
        }

        $prepRes = $this->runPsql($shadow, implode("\n", $prepStatements));
        if (! $prepRes['ok']) {
            $dropShadow();

            return $this->fail("Failed to prepare schemas/extensions in shadow DB {$shadow}: {$prepRes['error']}");
        }

        // 3. Filter only matching pre-created extension schemas and extensions from dump stream.
        $filtered = $this->filterPostgresDumpStream(
            $sql,
            $extensionSchemas,
            array_keys($extensions),
        );
        $replaySql = 'SET ROLE '.$this->pgIdent($ownerRole).";\n".$filtered."\nRESET ROLE;\n";

        $replayRes = $this->runPsql($shadow, $replaySql, singleTransaction: true);
        if (! $replayRes['ok']) {
            $dropShadow();

            return $this->fail("Postgres dump replay failed in shadow DB {$shadow}: {$replayRes['error']}");
        }

        // 4. Verify ownership of all tables & sequences in non-system schemas (ignoring extension-owned helpers).
        $ownerLit = $this->sqlLiteral($ownerRole);
        $verifySql = <<<SQL
SELECT count(*) FROM (
    SELECT schemaname, tablename FROM pg_tables
    WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
      AND tableowner <> {$ownerLit}
      AND NOT EXISTS (
          SELECT 1 FROM pg_depend d
          JOIN pg_class c ON c.oid = d.objid
          JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE d.deptype = 'e' AND n.nspname = schemaname AND c.relname = tablename
      )
    UNION ALL
    SELECT schemaname, sequencename FROM pg_sequences
    WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
      AND sequenceowner <> {$ownerLit}
      AND NOT EXISTS (
          SELECT 1 FROM pg_depend d
          JOIN pg_class c ON c.oid = d.objid
          JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE d.deptype = 'e' AND n.nspname = schemaname AND c.relname = sequencename
      )
) non_owned;
SQL;
        $verifyRes = $this->runPsql($shadow, $verifySql, tuplesOnly: true);
        if (! $verifyRes['ok'] || trim($verifyRes['output']) !== '0') {
            $dropShadow();

            return $this->fail("Ownership verification failed in shadow DB {$shadow}: ".trim($verifyRes['output'].' '.$verifyRes['error']));
        }

        // 5. Replay explicit non-owner grants (R16), if provided.
        $grants = array_values(array_filter(array_map('trim', $options['grants'] ?? []), fn (string $g): bool => $g !== ''));
        if ($grants !== []) {
            $grantRes = $this->runPsql($shadow, implode("\n", $grants), singleTransaction: true);
            if (! $grantRes['ok']) {
                $dropShadow();

                return $this->fail("Failed replaying grants in shadow DB {$shadow}: {$grantRes['error']}");
            }
        }

        // 6. Atomic rename swap.
        $existsRes = $this->runPsql(
            'postgres',
            'SELECT 1 FROM pg_database WHERE datname = '.$this->sqlLiteral($database).';',
            tuplesOnly: true,
        );
        $dbExists = $existsRes['ok'] && trim($existsRes['output']) === '1';

        $terminateSql = 'SELECT pg_terminate_backend(pid) FROM pg_stat_activity '
            .'WHERE datname IN ('.$this->sqlLiteral($database).', '.$this->sqlLiteral($shadow).') '
            .'AND pid <> pg_backend_pid();';

        if ($dbExists) {
            $this->runPsql('postgres', $terminateSql);
            $renameOld = $this->runPsql(
                'postgres',
                'ALTER DATABASE '.$this->pgIdent($database).' RENAME TO '.$this->pgIdent($old).';',
            );
            if (! $renameOld['ok']) {
                $dropShadow();

                return $this->fail("Failed renaming live Postgres DB {$database} to {$old}: {$renameOld['error']}");
            }

            $this->runPsql('postgres', $terminateSql);
            $renameNew = $this->runPsql(
                'postgres',
                'ALTER DATABASE '.$this->pgIdent($shadow).' RENAME TO '.$this->pgIdent($database).';',
            );
            if (! $renameNew['ok']) {
                // Rollback rename of original DB.
                $this->runPsql('postgres', 'ALTER DATABASE '.$this->pgIdent($old).' RENAME TO '.$this->pgIdent($database).';');
                $dropShadow();

                return $this->fail("Failed renaming shadow Postgres DB {$shadow} to {$database}: {$renameNew['error']}");
            }

            $this->runPsql('postgres', 'DROP DATABASE IF EXISTS '.$this->pgIdent($old).' WITH (FORCE);');
        } else {
            $this->runPsql('postgres', $terminateSql);
            $renameNew = $this->runPsql(
                'postgres',
                'ALTER DATABASE '.$this->pgIdent($shadow).' RENAME TO '.$this->pgIdent($database).';',
            );
            if (! $renameNew['ok']) {
                $dropShadow();

                return $this->fail("Failed renaming shadow Postgres DB {$shadow} to {$database}: {$renameNew['error']}");
            }
        }

        return true;
    }

    /**
     * @param  array{
     *   charset?: string,
     *   collation?: string,
     *   safetyDumpPath?: string
     * }  $options
     */
    public function restoreMysqlFamily(DatabaseDriver $driver, string $database, string $dumpPath, array $options = []): bool
    {
        $sql = $this->readDump($dumpPath);
        if ($sql === null) {
            return $this->fail("Dump file is missing or empty: {$dumpPath}");
        }

        $suffix = bin2hex(random_bytes(3));
        $shadow = self::shadowName($database, 'lk_restore', $suffix);
        $old = self::shadowName($database, 'lk_old', $suffix);

        $charset = trim((string) ($options['charset'] ?? ''));
        $collation = trim((string) ($options['collation'] ?? ''));

        $schemaInfo = $this->runMysql(
            $driver,
            null,
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.$this->sqlLiteral($database).' LIMIT 1;',
        );
        $dbExists = $schemaInfo['ok'] && trim($schemaInfo['output']) !== '';

        if ($dbExists && ($charset === '' || $collation === '')) {
            $parts = preg_split('/\s+/', trim($schemaInfo['output'])) ?: [];
            $charset = $charset !== '' ? $charset : ($parts[0] ?? 'utf8mb4');
            $collation = $collation !== '' ? $collation : ($parts[1] ?? 'utf8mb4_unicode_ci');
        }

        $charset = $charset !== '' ? $charset : 'utf8mb4';
        $collation = $collation !== '' ? $collation : 'utf8mb4_unicode_ci';

        $tempDir = null;
        if ($dbExists) {
            $safetyPath = $options['safetyDumpPath'] ?? null;
            if ($safetyPath === null || $safetyPath === '') {
                $tempDir = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
                $safetyPath = $tempDir->path('safety-dump.sql');
            }

            $safetyRes = $this->dumpMysql($driver, $database);
            if (! $safetyRes['ok'] || trim($safetyRes['output']) === '') {
                return $this->fail("Safety dump of live {$driver->value} DB {$database} failed: {$safetyRes['error']}");
            }
            file_put_contents($safetyPath, $safetyRes['output']);
            @chmod($safetyPath, 0600);
            if (! is_file($safetyPath) || filesize($safetyPath) === 0) {
                return $this->fail("Safety dump file is missing or empty: {$safetyPath}");
            }
        }

        $cleanup = function () use ($driver, $shadow, $old): void {
            $this->runMysql($driver, null, 'DROP DATABASE IF EXISTS '.$this->mysqlIdent($shadow).';');
            $this->runMysql($driver, null, 'DROP DATABASE IF EXISTS '.$this->mysqlIdent($old).';');
        };

        // 1. Create shadow DB with exact charset/collation.
        $createShadow = $this->runMysql(
            $driver,
            null,
            'DROP DATABASE IF EXISTS '.$this->mysqlIdent($shadow).'; '
            .'CREATE DATABASE '.$this->mysqlIdent($shadow)." CHARACTER SET {$charset} COLLATE {$collation};",
        );
        if (! $createShadow['ok']) {
            return $this->fail("Failed creating shadow DB {$shadow}: {$createShadow['error']}");
        }

        // 2. Strip any CREATE DATABASE / USE statements and import into shadow DB.
        $filteredDump = $this->filterMysqlDumpStream($sql);
        $importSql = 'USE '.$this->mysqlIdent($shadow).";\n".$filteredDump;
        $importRes = $this->runMysql($driver, $shadow, $importSql);
        if (! $importRes['ok']) {
            $cleanup();

            return $this->fail("Failed importing dump into shadow DB {$shadow}: {$importRes['error']}");
        }

        // 3. Verify base table counts in shadow DB.
        $shadowTables = $this->listMysqlTables($driver, $shadow, 'BASE TABLE');
        if (preg_match('/\bCREATE\s+TABLE\b/i', $filteredDump) === 1 && $shadowTables === []) {
            $cleanup();

            return $this->fail("Shadow DB {$shadow} contains 0 base tables after import.");
        }

        // 4. Capture views, routines, and triggers from shadow DB, and drop triggers on shadow DB before RENAME TABLE.
        $views = $this->captureMysqlViews($driver, $shadow, $database);
        $routines = $this->captureMysqlRoutines($driver, $shadow, $database);
        $triggers = $this->captureMysqlTriggers($driver, $shadow, $database);

        foreach ($triggers as $triggerName => $_ddl) {
            $dropTrig = $this->runMysql(
                $driver,
                $shadow,
                'DROP TRIGGER IF EXISTS '.$this->mysqlIdent($shadow).'.'.$this->mysqlIdent($triggerName).';',
            );
            if (! $dropTrig['ok']) {
                $cleanup();

                return $this->fail("Failed dropping trigger {$triggerName} on shadow DB {$shadow}: {$dropTrig['error']}");
            }
        }

        // 5. Ensure target DB & old holding DB exist, then atomically RENAME TABLE.
        $prepTargetSql = 'CREATE DATABASE IF NOT EXISTS '.$this->mysqlIdent($database)." CHARACTER SET {$charset} COLLATE {$collation}; "
            .'DROP DATABASE IF EXISTS '.$this->mysqlIdent($old).'; '
            .'CREATE DATABASE '.$this->mysqlIdent($old)." CHARACTER SET {$charset} COLLATE {$collation};";
        $prepTargetRes = $this->runMysql($driver, null, $prepTargetSql);
        if (! $prepTargetRes['ok']) {
            $cleanup();

            return $this->fail("Failed preparing target/old DB for swap: {$prepTargetRes['error']}");
        }

        $existingTriggers = $this->captureMysqlTriggers($driver, $database, $database);
        foreach (array_keys($existingTriggers) as $existingTrigger) {
            $dropExistingTrig = $this->runMysql(
                $driver,
                $database,
                'DROP TRIGGER IF EXISTS '.$this->mysqlIdent($database).'.'.$this->mysqlIdent($existingTrigger).';',
            );
            if (! $dropExistingTrig['ok']) {
                foreach ($existingTriggers as $restoreTriggerDdl) {
                    $this->runMysql($driver, $database, "USE {$this->mysqlIdent($database)};\nDELIMITER //\n{$restoreTriggerDdl}//\nDELIMITER ;\n");
                }
                $cleanup();

                return $this->fail("Failed dropping existing trigger {$existingTrigger} on {$database}: {$dropExistingTrig['error']}");
            }
        }

        $existingTables = $this->listMysqlTables($driver, $database, 'BASE TABLE');
        $renamePairs = [];
        foreach ($existingTables as $table) {
            $renamePairs[] = $this->mysqlIdent($database).'.'.$this->mysqlIdent($table)
                .' TO '.$this->mysqlIdent($old).'.'.$this->mysqlIdent($table);
        }
        foreach ($shadowTables as $table) {
            $renamePairs[] = $this->mysqlIdent($shadow).'.'.$this->mysqlIdent($table)
                .' TO '.$this->mysqlIdent($database).'.'.$this->mysqlIdent($table);
        }

        if ($renamePairs !== []) {
            $renameSql = "SET FOREIGN_KEY_CHECKS=0;\nRENAME TABLE ".implode(",\n  ", $renamePairs).";\nSET FOREIGN_KEY_CHECKS=1;\n";
            $renameRes = $this->runMysql($driver, null, $renameSql);
            if (! $renameRes['ok']) {
                foreach ($existingTriggers as $restoreTriggerDdl) {
                    $this->runMysql($driver, $database, "USE {$this->mysqlIdent($database)};\nDELIMITER //\n{$restoreTriggerDdl}//\nDELIMITER ;\n");
                }
                $cleanup();

                return $this->fail("Atomic RENAME TABLE swap failed for {$database}: {$renameRes['error']}");
            }
        }

        // 6. Drop stale views/routines/triggers on target DB, then replay captured objects onto target DB.
        foreach ($this->listMysqlTables($driver, $database, 'VIEW') as $oldView) {
            $this->runMysql($driver, $database, 'DROP VIEW IF EXISTS '.$this->mysqlIdent($database).'.'.$this->mysqlIdent($oldView).';');
        }
        foreach ($this->listMysqlRoutines($driver, $database) as $oldRoutine) {
            $this->runMysql($driver, $database, "DROP {$oldRoutine['type']} IF EXISTS ".$this->mysqlIdent($database).'.'.$this->mysqlIdent($oldRoutine['name']).';');
        }
        foreach ($this->listMysqlTriggers($driver, $database) as $oldTrigger) {
            $this->runMysql($driver, $database, 'DROP TRIGGER IF EXISTS '.$this->mysqlIdent($database).'.'.$this->mysqlIdent($oldTrigger).';');
        }

        foreach ($views as $viewName => $ddl) {
            $res = $this->runMysql($driver, $database, "USE {$this->mysqlIdent($database)};\n{$ddl};");
            if (! $res['ok']) {
                return $this->fail("Failed replaying view {$viewName} onto {$database}: {$res['error']}");
            }
        }

        foreach ($routines as $routineName => $ddl) {
            $script = "USE {$this->mysqlIdent($database)};\nDELIMITER //\n{$ddl}//\nDELIMITER ;\n";
            $res = $this->runMysql($driver, $database, $script);
            if (! $res['ok']) {
                return $this->fail("Failed replaying routine {$routineName} onto {$database}: {$res['error']}");
            }
        }

        foreach ($triggers as $triggerName => $ddl) {
            $script = "USE {$this->mysqlIdent($database)};\nDELIMITER //\n{$ddl}//\nDELIMITER ;\n";
            $res = $this->runMysql($driver, $database, $script);
            if (! $res['ok']) {
                return $this->fail("Failed replaying trigger {$triggerName} onto {$database}: {$res['error']}");
            }
        }

        // 7. Verify final base table count on target DB before dropping $old and $shadow.
        $finalTables = $this->listMysqlTables($driver, $database, 'BASE TABLE');
        if (count($finalTables) !== count($shadowTables)) {
            return $this->fail("Post-swap table count mismatch on {$database}: expected ".count($shadowTables).', got '.count($finalTables));
        }

        $cleanup();

        return true;
    }

    /**
     * Filter ONLY matching pre-created extension-hosting schemas and extensions from a pg_dump stream.
     * Preserves `\restrict`/`\unrestrict`, all other schemas (e.g. `eventstore`), tables, data, and grants.
     *
     * @param  list<string>  $preCreatedSchemas
     * @param  list<string>  $preCreatedExtensions
     */
    public function filterPostgresDumpStream(string $sql, array $preCreatedSchemas, array $preCreatedExtensions): string
    {
        $schemaMap = array_flip(array_map('strtolower', $preCreatedSchemas));
        $extMap = array_flip(array_map('strtolower', $preCreatedExtensions));

        $lines = preg_split("/\r?\n/", $sql) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*CREATE\s+SCHEMA\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|([A-Za-z_][A-Za-z0-9_$]*))\s*;\s*$/i', $line, $m)) {
                $name = strtolower($m[1] !== '' ? $m[1] : $m[2]);
                if (isset($schemaMap[$name])) {
                    continue;
                }
            }

            if (preg_match('/^\s*CREATE\s+EXTENSION\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|([A-Za-z_][A-Za-z0-9_$-]*))\b.*;\s*$/i', $line, $m)) {
                $ext = strtolower($m[1] !== '' ? $m[1] : $m[2]);
                if (isset($extMap[$ext])) {
                    continue;
                }
            }

            if (preg_match('/^\s*COMMENT\s+ON\s+EXTENSION\s+(?:"([^"]+)"|([A-Za-z_][A-Za-z0-9_$-]*))\s+IS\s+.*;\s*$/i', $line, $m)) {
                $ext = strtolower($m[1] !== '' ? $m[1] : $m[2]);
                if (isset($extMap[$ext])) {
                    continue;
                }
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    public function filterMysqlDumpStream(string $sql): string
    {
        $lines = preg_split("/\r?\n/", $sql) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:CREATE\s+(?:DATABASE|SCHEMA)\b|USE\s+)/i', $line)) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * @return array{0: list<string>, 1: list<array{name: string, schema: string}>}
     */
    private function detectPostgresExtensions(string $sql): array
    {
        $schemas = [];
        $extensions = [];

        foreach (preg_split("/\r?\n/", $sql) ?: [] as $line) {
            if (preg_match('/^\s*CREATE\s+EXTENSION\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|([A-Za-z_][A-Za-z0-9_$-]*))(?:\s+WITH\s+SCHEMA\s+(?:"([^"]+)"|([A-Za-z_][A-Za-z0-9_$]*)))?/i', $line, $m)) {
                $name = $m[1] !== '' ? $m[1] : $m[2];
                $schema = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : 'public');
                $extensions[] = ['name' => $name, 'schema' => $schema];
                if ($schema !== 'public' && $schema !== 'pg_catalog') {
                    $schemas[] = $schema;
                }
            }
        }

        return [array_values(array_unique($schemas)), $extensions];
    }

    /** @return array{ok: bool, output: string, error: string} */
    private function runPsql(string $database, string $sql, bool $singleTransaction = false, bool $tuplesOnly = false): array
    {
        $cmd = ['psql', '-U', 'postgres', '-v', 'ON_ERROR_STOP=1'];
        if ($singleTransaction) {
            $cmd[] = '--single-transaction';
        }
        if ($tuplesOnly) {
            $cmd[] = '-tAc';
            $cmd[] = $sql;
            $cmd[] = '-d';
            $cmd[] = $database;

            return ($this->runner)($cmd, null);
        }

        $cmd[] = '-d';
        $cmd[] = $database;

        return ($this->runner)($cmd, $sql);
    }

    /** @return array{ok: bool, output: string, error: string} */
    private function runMysql(DatabaseDriver $driver, ?string $database, string $sql): array
    {
        $client = $driver === DatabaseDriver::MARIADB ? 'mariadb' : 'mysql';
        $dbArg = $database !== null ? ' '.escapeshellarg($database) : '';
        $sh = 'PW="${MYSQL_ROOT_PASSWORD:-${MARIADB_ROOT_PASSWORD:-}}"; exec '.$client.' -uroot -p"$PW" -N -B'.$dbArg;

        return ($this->runner)(['sh', '-c', $sh], $sql);
    }

    /** @return array{ok: bool, output: string, error: string} */
    private function dumpMysql(DatabaseDriver $driver, string $database): array
    {
        $dumper = $driver === DatabaseDriver::MARIADB ? 'mariadb-dump' : 'mysqldump';
        $sh = 'PW="${MYSQL_ROOT_PASSWORD:-${MARIADB_ROOT_PASSWORD:-}}"; exec '.$dumper.' -uroot -p"$PW" --routines --triggers --single-transaction '.escapeshellarg($database);

        return ($this->runner)(['sh', '-c', $sh], null);
    }

    /** @return list<string> */
    private function listMysqlTables(DatabaseDriver $driver, string $schema, string $tableType): array
    {
        $sql = 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$this->sqlLiteral($schema)
            .' AND TABLE_TYPE = '.$this->sqlLiteral($tableType).' ORDER BY TABLE_NAME;';
        $res = $this->runMysql($driver, null, $sql);
        if (! $res['ok']) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($res['output']))), fn (string $v): bool => $v !== ''));
    }

    /** @return list<array{type: string, name: string}> */
    private function listMysqlRoutines(DatabaseDriver $driver, string $schema): array
    {
        $sql = 'SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '.$this->sqlLiteral($schema).' ORDER BY ROUTINE_NAME;';
        $res = $this->runMysql($driver, null, $sql);
        if (! $res['ok']) {
            return [];
        }

        $out = [];
        foreach (explode("\n", trim($res['output'])) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, "\t")) {
                continue;
            }
            [$type, $name] = explode("\t", $line, 2);
            $type = strtoupper(trim($type));
            if (in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
                $out[] = ['type' => $type, 'name' => trim($name)];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function listMysqlTriggers(DatabaseDriver $driver, string $schema): array
    {
        $sql = 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '.$this->sqlLiteral($schema).' ORDER BY TRIGGER_NAME;';
        $res = $this->runMysql($driver, null, $sql);
        if (! $res['ok']) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($res['output']))), fn (string $v): bool => $v !== ''));
    }

    /** @return array<string, string> */
    private function captureMysqlViews(DatabaseDriver $driver, string $shadow, string $target): array
    {
        $views = [];
        foreach ($this->listMysqlTables($driver, $shadow, 'VIEW') as $view) {
            $res = $this->runMysql($driver, null, 'SHOW CREATE VIEW '.$this->mysqlIdent($shadow).'.'.$this->mysqlIdent($view).';');
            if (! $res['ok']) {
                continue;
            }
            $cols = explode("\t", trim($res['output'], "\r\n"));
            if (isset($cols[1])) {
                $ddl = $this->unescapeMysqlBatchValue($cols[1]);
                $ddl = str_replace("`{$shadow}`.", "`{$target}`.", $ddl);
                $views[$view] = $ddl;
            }
        }

        return $views;
    }

    /** @return array<string, string> */
    private function captureMysqlRoutines(DatabaseDriver $driver, string $shadow, string $target): array
    {
        $routines = [];
        foreach ($this->listMysqlRoutines($driver, $shadow) as $routine) {
            $res = $this->runMysql($driver, null, "SHOW CREATE {$routine['type']} ".$this->mysqlIdent($shadow).'.'.$this->mysqlIdent($routine['name']).';');
            if (! $res['ok']) {
                continue;
            }
            $cols = explode("\t", trim($res['output'], "\r\n"));
            if (isset($cols[2])) {
                $ddl = $this->unescapeMysqlBatchValue($cols[2]);
                $ddl = str_replace("`{$shadow}`.", "`{$target}`.", $ddl);
                $routines[$routine['name']] = $ddl;
            }
        }

        return $routines;
    }

    /** @return array<string, string> */
    private function captureMysqlTriggers(DatabaseDriver $driver, string $shadow, string $target): array
    {
        $triggers = [];
        foreach ($this->listMysqlTriggers($driver, $shadow) as $trigger) {
            $res = $this->runMysql($driver, $shadow, 'SHOW CREATE TRIGGER '.$this->mysqlIdent($shadow).'.'.$this->mysqlIdent($trigger).';');
            if (! $res['ok']) {
                continue;
            }
            $cols = explode("\t", trim($res['output'], "\r\n"));
            if (isset($cols[2])) {
                $ddl = $this->unescapeMysqlBatchValue($cols[2]);
                $ddl = str_replace("`{$shadow}`.", "`{$target}`.", $ddl);
                $triggers[$trigger] = $ddl;
            }
        }

        return $triggers;
    }

    private function unescapeMysqlBatchValue(string $value): string
    {
        return strtr($value, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\\\' => '\\',
        ]);
    }

    private function readDump(string $dumpPath): ?string
    {
        if (! is_file($dumpPath) || filesize($dumpPath) === 0) {
            return null;
        }

        if (str_ends_with($dumpPath, '.gz')) {
            $handle = @gzopen($dumpPath, 'rb');
            if ($handle === false) {
                return null;
            }
            $buf = '';
            while (! gzeof($handle)) {
                $chunk = gzread($handle, 65536);
                if ($chunk === false) {
                    gzclose($handle);

                    return null;
                }
                $buf .= $chunk;
            }
            gzclose($handle);

            return trim($buf) === '' ? null : $buf;
        }

        $contents = file_get_contents($dumpPath);

        return $contents === false || trim($contents) === '' ? null : $contents;
    }

    private function pgIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function mysqlIdent(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function sqlLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function fail(string $message): false
    {
        $this->lastError = $message;

        return false;
    }
}
