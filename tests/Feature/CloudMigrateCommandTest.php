<?php

/**
 * cloud:migrate orchestrates existing commands — it owns none of their
 * internals. These tests intercept every nested $this->call()/Artisan::call()
 * invocation with a scripted result and assert ONLY this command's own
 * sequencing/decision logic: which sub-commands run, in what order, with
 * what arguments, and how their exit codes/JSON shape the next step. The
 * two direct (non-sub-command) reads this command does itself —
 * readBackupConfig()/fetchManifest(), both plain `kubectl`/`aws` Process
 * calls — are faked for real via Process::fake(), same shapes already
 * proven in BackupRunCommandTest/BackupRestoreCommandTest.
 */

use App\Commands\Cloud\CloudMigrateCommand;
use App\Data\CloudData;
use App\Data\ConfigData;
use Illuminate\Console\OutputStyle;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Prompt::interactive(false);

    $this->temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $this->tempDir = $this->temporaryDirectory->path();
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function (): void {
    chdir($this->originalDir);
    $this->temporaryDirectory->delete();
});

/**
 * 'managed' defaults to ['mysql', 'storage'] so ownStorageItems() returns []
 * and tests not about that feature don't also have to fake its kubectl exec
 * calls — tests for migrateOwnStorage() itself override 'managed' back to [].
 */
function migrateProject(array $overrides = []): ConfigData
{
    $config = ConfigData::from(array_merge([
        'name' => 'migratetest',
        'database' => 'mysql',
        'environments' => ['local' => [], 'production' => ['managed' => ['mysql', 'storage']]],
    ], $overrides));

    $config->setCloud('production', new CloudData(context: 'larakube-1.2.3.4'));

    return $config;
}

function saveMigrateProject(string $dir, array $overrides = []): ConfigData
{
    $config = migrateProject($overrides);
    $config->setPath($dir);
    $config->saveToFile($dir);

    return $config;
}

/**
 * Builds a runnable CloudMigrateCommand with scripted outcomes for every
 * sub-command it calls via $this->call() — keyed by command name. A missing
 * key fails the test loudly (unexpected sub-command invocation) rather than
 * silently succeeding, so a reordering/renaming bug can't hide.
 */
function migrateRunner(array $options, array $scripted = []): CloudMigrateCommand
{
    $command = new class extends CloudMigrateCommand
    {
        public array $scripted = [];

        public array $calls = [];

        public BufferedOutput $buffer;

        public function bindOptions(array $options): void
        {
            $this->buffer = new BufferedOutput;
            $this->input = new ArrayInput($options, $this->getDefinition());
            $this->input->setInteractive(false);
            $this->output = new OutputStyle($this->input, $this->buffer);
        }

        public function call($command, array $arguments = [])
        {
            $this->calls[] = ['command' => $command, 'arguments' => $arguments];

            if (! array_key_exists($command, $this->scripted)) {
                throw new RuntimeException("Unscripted sub-command call: {$command}");
            }

            return $this->scripted[$command];
        }
    };

    $command->scripted = $scripted;
    $command->bindOptions($options);

    return $command;
}

/** The command interleaves human lines with one final JSON line under --json. */
function lastJsonLine(string $output): ?array
{
    $lines = preg_split('/\R/', trim($output)) ?: [];

    return json_decode((string) end($lines), true);
}

// --- argument validation ----------------------------------------------------

test('requires either --to-context or --provision-managed', function (): void {
    saveMigrateProject($this->tempDir);

    $command = migrateRunner(['environment' => 'production', '--force' => true]);

    expect($command->handle())->toBe(1);
});

test('rejects passing both --to-context and --provision-managed', function (): void {
    saveMigrateProject($this->tempDir);

    $command = migrateRunner([
        'environment' => 'production', '--to-context' => 'do-nyc1-new', '--provision-managed' => true, '--force' => true,
    ]);

    expect($command->handle())->toBe(1);
});

test('aborts when the destination equals the current source', function (): void {
    saveMigrateProject($this->tempDir);

    $command = migrateRunner([
        'environment' => 'production', '--to-context' => 'larakube-1.2.3.4', '--force' => true,
    ]);

    expect($command->handle())->toBe(1);
});

test('local is never a migratable cloud environment', function (): void {
    saveMigrateProject($this->tempDir);

    $command = migrateRunner([
        'environment' => 'local', '--to-context' => 'do-nyc1-new', '--force' => true,
    ]);

    expect($command->handle())->toBe(1);
});

// --- happy path, no Commons --------------------------------------------------

test('migrates secrets, rebinds via --only=target, and redeploys when there is no Commons to copy', function (): void {
    saveMigrateProject($this->tempDir); // plex: [] by default

    $command = migrateRunner(
        ['environment' => 'production', '--to-context' => 'do-nyc1-new', '--force' => true, '--json' => true],
        [
            'dotenv:push' => 0,
            'cloud:configure' => 0,
            'cloud:deploy' => 0,
        ],
    );

    $exit = $command->handle();

    expect($exit)->toBe(0);

    $byCommand = collect($command->calls)->keyBy('command');

    expect($byCommand->has('cloud:configure'))->toBeTrue()
        ->and($byCommand['cloud:configure']['arguments']['--only'])->toBe('target')
        ->and($byCommand['cloud:configure']['arguments']['--rebind'])->toBeTrue()
        ->and($byCommand['cloud:configure']['arguments']['--context'])->toBe('do-nyc1-new')
        ->and($byCommand['cloud:deploy']['arguments']['environment'])->toBe('production')
        ->and($byCommand->has('plex:export'))->toBeFalse();

    $output = lastJsonLine($command->buffer->fetch());

    expect($output['success'])->toBeTrue()
        ->and($output['commonsMigrated'])->toBeFalse()
        ->and($output['destinationContext'])->toBe('do-nyc1-new');
});

test('a failed rebind stops before any redeploy, and never reports success', function (): void {
    saveMigrateProject($this->tempDir);

    $command = migrateRunner(
        ['environment' => 'production', '--to-context' => 'do-nyc1-new', '--force' => true],
        [
            'dotenv:push' => 0,
            'cloud:configure' => 1,
        ],
    );

    expect($command->handle())->toBe(1);

    $commands = array_column($command->calls, 'command');
    expect($commands)->not->toContain('cloud:deploy');
});

// --- --provision-managed: reads the context back from cloud:create ---------

test('--provision-managed reads the destination context from cloud:create\'s buffered --json result', function (): void {
    saveMigrateProject($this->tempDir);

    Artisan::shouldReceive('call')
        ->once()
        ->withArgs(function (string $command, array $args, $buffer): bool {
            expect($command)->toBe('cloud:create')
                ->and($args['--provider'])->toBe('do')
                ->and($args['--managed'])->toBeTrue();

            $buffer->write(json_encode(['success' => true, 'context' => 'do-nyc1-new-managed']));

            return true;
        })
        ->andReturn(0);

    $command = migrateRunner(
        ['environment' => 'production', '--provision-managed' => true, '--provider' => 'do', '--force' => true, '--json' => true],
        [
            'dotenv:push' => 0,
            'cloud:configure' => 0,
            'cloud:deploy' => 0,
        ],
    );

    expect($command->handle())->toBe(0);

    $byCommand = collect($command->calls)->keyBy('command');
    expect($byCommand['cloud:configure']['arguments']['--context'])->toBe('do-nyc1-new-managed');
});

test('a provisioning failure stops the migration before anything else runs', function (): void {
    saveMigrateProject($this->tempDir);

    Artisan::shouldReceive('call')
        ->once()
        ->withArgs(function (string $command, array $args, $buffer): bool {
            $buffer->write(json_encode(['success' => false, 'error' => 'quota exceeded']));

            return true;
        })
        ->andReturn(1);

    $command = migrateRunner([
        'environment' => 'production', '--provision-managed' => true, '--provider' => 'do', '--force' => true,
    ]);

    expect($command->handle())->toBe(1);
});

// --- Commons migration -------------------------------------------------------

function migrateBackupFakes(): array
{
    $val = fn (string $v) => Process::result(output: base64_encode($v));

    $manifest = json_encode([
        'version' => 1,
        'taken_at' => '2026-10-10T00:00:00Z',
        'engine' => 'mysql',
        'items' => [
            ['kind' => 'database', 'name' => 'plex-mysql', 'object' => 'db.sql.gz.age', 'bytes' => 100],
            ['kind' => 'volume', 'name' => 'plex-seaweedfs', 'object' => 'vol.tar.gz.age', 'bytes' => 200],
        ],
    ]);

    return [
        '*larakube-backup-config*bucket*' => $val('off-site-bucket'),
        '*larakube-backup-config*endpoint*' => $val('https://s3.us-west-004.backblazeb2.com'),
        '*larakube-backup-config*access-key*' => $val('AK'),
        '*larakube-backup-config*secret-key*' => $val('SK'),
        '*larakube-backup-config*passphrase*' => $val('test-passphrase'),
        '*larakube-backup-config*region*' => $val('us-east-1'),
        '*s3 cp*' => Process::result(output: $manifest),
        '*' => Process::result(output: ''),
    ];
}

test('a Commons environment rebuilds structure, copies data, and restores every manifest item', function (): void {
    saveMigrateProject($this->tempDir, ['environments' => ['local' => [], 'production' => ['plex' => ['mysql'], 'managed' => ['storage']]]]);

    Process::fake(migrateBackupFakes());

    Artisan::shouldReceive('call')
        ->once()
        ->withArgs(function (string $command, array $args, $buffer): bool {
            expect($command)->toBe('backup:run');
            $buffer->write(json_encode(['success' => true, 'backup' => ['id' => 'bk-123', 'bytes' => 300, 'items' => 2]]));

            return true;
        })
        ->andReturn(0);

    $command = migrateRunner(
        ['environment' => 'production', '--to-context' => 'do-nyc1-new', '--force' => true, '--json' => true],
        [
            'plex:export' => 0,
            'plex:init' => 0,
            'backup:restore' => 0,
            'dotenv:push' => 0,
            'cloud:configure' => 0,
            'cloud:deploy' => 0,
        ],
    );

    expect($command->handle())->toBe(0);

    $restoreCalls = array_values(array_filter($command->calls, fn ($c) => $c['command'] === 'backup:restore'));

    expect($restoreCalls)->toHaveCount(2)
        ->and($restoreCalls[0]['arguments']['--database'])->toBe('plex-mysql')
        ->and($restoreCalls[0]['arguments']['--backup'])->toBe('bk-123')
        ->and($restoreCalls[0]['arguments']['--context'])->toBe('do-nyc1-new')
        ->and($restoreCalls[1]['arguments']['--volume'])->toBe('plex-seaweedfs');

    $output = lastJsonLine($command->buffer->fetch());
    expect($output['commonsMigrated'])->toBeTrue();
});

test('a missing backup destination rebuilds Commons structure but warns data was not copied, without failing the migration', function (): void {
    saveMigrateProject($this->tempDir, ['environments' => ['local' => [], 'production' => ['plex' => ['mysql'], 'managed' => ['storage']]]]);

    Process::fake(['*' => Process::result(output: '')]); // no bucket configured

    $command = migrateRunner(
        ['environment' => 'production', '--to-context' => 'do-nyc1-new', '--force' => true],
        [
            'plex:export' => 0,
            'plex:init' => 0,
            'dotenv:push' => 0,
            'cloud:configure' => 0,
            'cloud:deploy' => 0,
        ],
    );

    expect($command->handle())->toBe(0);

    $commands = array_column($command->calls, 'command');
    expect($commands)->not->toContain('backup:restore');
});

test('--quiesce pauses the app before the Commons snapshot and resumes it after', function (): void {
    saveMigrateProject($this->tempDir, ['environments' => ['local' => [], 'production' => ['plex' => ['mysql'], 'managed' => ['storage']]]]);

    // The specific pattern must be listed BEFORE migrateBackupFakes()'s own
    // trailing '*' catch-all — Process::fake() matches in insertion order, so
    // appending a new key after an existing '*' would never be reached.
    Process::fake(array_merge(
        ['*get deployments -n*-o json*' => Process::result(output: json_encode([
            'items' => [
                ['metadata' => ['name' => 'web'], 'spec' => ['replicas' => 3]],
            ],
        ]))],
        migrateBackupFakes(),
    ));

    Artisan::shouldReceive('call')
        ->once()
        ->withArgs(function (string $command, array $args, $buffer): bool {
            $buffer->write(json_encode(['success' => true, 'backup' => ['id' => 'bk-123', 'bytes' => 300, 'items' => 2]]));

            return true;
        })
        ->andReturn(0);

    $command = migrateRunner(
        ['environment' => 'production', '--to-context' => 'do-nyc1-new', '--force' => true, '--quiesce' => true],
        [
            'plex:export' => 0,
            'plex:init' => 0,
            'backup:restore' => 0,
            'dotenv:push' => 0,
            'cloud:configure' => 0,
            'cloud:deploy' => 0,
        ],
    );

    expect($command->handle())->toBe(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'scale deployment/web --replicas=0'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'scale deployment/web --replicas=3'));
});

// --- own-storage copy (SQLite/self-hosted DB/local storage, post-redeploy) --

/**
 * Simulates the `> 'file'` redirect a real exec would produce — Process::fake()
 * never touches the filesystem, so the dump/archive "out" steps must write
 * their own dummy bytes for the subsequent sizeOf() check to see anything.
 */
function writesRedirectedFile(): Closure
{
    return function (PendingProcess $process) {
        if (preg_match("/> '([^']+)'$/", $process->command, $m)) {
            file_put_contents($m[1], str_repeat('x', 200));
        }

        return Process::result(exitCode: 0);
    };
}

function migrateOwnStorageFakes(): array
{
    return [
        '*exec deploy/web*tar czf*' => writesRedirectedFile(),
        '*exec deploy/mysql*sh -c*' => writesRedirectedFile(),
        '*exec -i deploy/web*tar xzf*' => Process::result(exitCode: 0),
        '*exec -i deploy/mysql*sh -c*' => Process::result(exitCode: 0),
        '*' => Process::result(output: ''),
    ];
}

test('after a successful redeploy, the self-hosted database and local storage are copied live', function (): void {
    saveMigrateProject($this->tempDir, ['environments' => ['local' => [], 'production' => ['managed' => []]]]);

    Process::fake(migrateOwnStorageFakes());

    $command = migrateRunner(
        ['environment' => 'production', '--to-context' => 'do-nyc1-new', '--force' => true, '--json' => true],
        [
            'dotenv:push' => 0,
            'cloud:configure' => 0,
            'cloud:deploy' => 0,
        ],
    );

    expect($command->handle())->toBe(0);

    $output = lastJsonLine($command->buffer->fetch());

    expect($output['ownStorageCopied'])->toContain('self-hosted mysql database')
        ->and($output['ownStorageCopied'])->toContain('local storage/app/public')
        ->and($output['ownStorageFailed'])->toBe([]);

    Process::assertRan(fn ($process) => str_contains($process->command, 'exec deploy/mysql -n') && str_contains($process->command, '-- sh -c'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'exec -i deploy/mysql'));
});

test('a failed own-storage copy is reported but does not fail the overall migration', function (): void {
    saveMigrateProject($this->tempDir, ['environments' => ['local' => [], 'production' => ['managed' => []]]]);

    $fakes = migrateOwnStorageFakes();
    $fakes['*exec deploy/mysql*sh -c*'] = Process::result(output: '', exitCode: 1); // the dump itself fails
    Process::fake($fakes);

    $command = migrateRunner(
        ['environment' => 'production', '--to-context' => 'do-nyc1-new', '--force' => true, '--json' => true],
        [
            'dotenv:push' => 0,
            'cloud:configure' => 0,
            'cloud:deploy' => 0,
        ],
    );

    expect($command->handle())->toBe(0); // cloud:deploy already succeeded — a copy failure doesn't un-succeed it

    $output = lastJsonLine($command->buffer->fetch());

    expect($output['ownStorageFailed'])->toContain('self-hosted mysql database')
        ->and($output['ownStorageCopied'])->toContain('local storage/app/public');
});
