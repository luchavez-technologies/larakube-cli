<?php

use App\Data\ConfigData;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** A project in a temp dir, optionally joined to the Commons. */
function downProject(array $plex): TemporaryDirectory
{
    $dir = TemporaryDirectory::make();

    ConfigData::from([
        'name' => 'hello-next',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'environments' => ['local' => ['plex' => $plex, 'managed' => $plex]],
    ])->saveToFile($dir->path());

    return $dir;
}

/** Cluster fakes with the given Commons registry tenants. */
function downFakes(array $tenants): void
{
    Process::fake([
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        '*get configmap plex-registry*' => Process::result(output: (string) json_encode(['tenants' => $tenants])),
        '*pg_database*' => Process::result(output: 'hello_next_local'),
        '*exec *' => Process::result(output: 'ok'),
        '*create configmap plex-registry*' => Process::result(output: 'configured'),
        '*' => Process::result(output: ''),
    ]);
}

/** Run `down` from inside the project, restoring the working directory after. */
function downRun(object $test, TemporaryDirectory $project, string $arguments, callable $expectations): void
{
    $previous = getcwd();
    chdir($project->path());

    try {
        $expectations($test->artisan("down {$arguments}"))->run();
    } finally {
        chdir($previous !== false ? $previous : sys_get_temp_dir());
    }
}

function downRegisteredTenant(): array
{
    return ['hello_next_local' => [
        'db' => 'hello_next_local',
        'db_service' => 'postgres',
        'redis_index' => 4,
        'namespace' => 'hello-next-local',
    ]];
}

test('down --full evicts the project\'s own local Commons tenant', function (): void {
    downFakes(downRegisteredTenant());
    $project = downProject(['postgres', 'redis']);

    downRun($this, $project, 'local --full --force', fn ($command) => $command
        ->expectsOutputToContain('plex:join local')
        ->assertExitCode(0));

    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'redis-cli -n 4 FLUSHDB'));
    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'create configmap plex-registry'));

    $project->delete();
});

test('plain down keeps the Commons tenant for the next up', function (): void {
    downFakes(downRegisteredTenant());
    $project = downProject(['postgres', 'redis']);

    downRun($this, $project, 'local --force', fn ($command) => $command->assertExitCode(0));

    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'FLUSHDB'));
    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'create configmap plex-registry'));

    $project->delete();
});

test('down --full leaves the Commons alone for a project that never joined', function (): void {
    downFakes(downRegisteredTenant());
    $project = downProject([]);

    downRun($this, $project, 'local --full --force', fn ($command) => $command->assertExitCode(0));

    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'plex-registry'));

    $project->delete();
});

test('down --full skips eviction when the tenant is not registered', function (): void {
    downFakes([]);
    $project = downProject(['postgres', 'redis']);

    downRun($this, $project, 'local --full --force', fn ($command) => $command
        ->expectsOutputToContain('Next steps: larakube up')
        ->assertExitCode(0));

    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'FLUSHDB'));
    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'create configmap plex-registry'));

    $project->delete();
});

test('down --full --dry-run names the tenant it would evict without touching it', function (): void {
    downFakes(downRegisteredTenant());
    $project = downProject(['postgres', 'redis']);

    downRun($this, $project, 'local --full --dry-run', fn ($command) => $command
        ->expectsOutputToContain("Would evict Plex Commons tenant 'hello_next_local'")
        ->assertExitCode(0));

    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'plex-registry'));

    $project->delete();
});
