<?php

use App\Commands\Plex\PlexJoinCommand;
use App\Data\ConfigData;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Found live 2026-09-09: `statamic:new` scaffolded a project whose Postgres,
 * SeaweedFS and Meilisearch all had room on the Commons, but the join aborted
 * outright because the Commons Redis had all 16 logical DBs allocated — twelve
 * of them held by deleted scratch projects. The app then deployed four
 * self-hosted pods, and the one-line warning scrolled past under composer
 * output. Redis exhaustion must cost the tenant Redis, and nothing else.
 */

/** A join command whose only stub is `heal`, so handle() runs for real. */
function plexJoinRedisFullCommand(BufferedOutput $output, array $arguments): object
{
    $command = new class extends PlexJoinCommand
    {
        /** @var array<int, string> */
        public array $calledSilently = [];

        public function callSilent($command, array $arguments = []): int
        {
            $this->calledSilently[] = $command;

            return 0;
        }
    };

    // --no-interaction is a global option contributed by the console
    // application, so a bare command definition doesn't carry it.
    $command->addOption('no-interaction', 'n', InputOption::VALUE_NONE);

    $input = new ArrayInput($arguments);
    $input->bind($command->getDefinition());
    $input->setInteractive(false);
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));

    return $command;
}

/** A project wired to all four Commons-eligible services. */
function plexJoinRedisFullProject(): TemporaryDirectory
{
    $dir = TemporaryDirectory::make();

    ConfigData::from([
        'name' => 'hey-statamic',
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'objectStorage' => 'seaweedfs',
        'scoutDriver' => 'meilisearch',
        'environments' => ['local' => []],
    ])->saveToFile($dir->path());

    file_put_contents($dir->path().'/.env', "APP_NAME=Hey\nREDIS_HOST=redis.hey-statamic-local.svc.cluster.local\n");

    return $dir;
}

/** All 16 Redis indexes taken; every other Commons service has room. */
function plexJoinRedisFullFakes(): array
{
    $tenants = [];
    for ($i = 0; $i < 16; $i++) {
        $tenants["squatter_{$i}"] = ['db' => "squatter_{$i}", 'db_service' => 'postgres', 'redis_index' => $i];
    }

    return [
        '*cluster-info*' => Process::result(output: 'Kubernetes control plane is running'),
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true, 'host' => 's3.test'],
                'meilisearch' => ['enabled' => true],
            ]]),
        ),
        '*get configmap plex-registry*' => Process::result(output: (string) json_encode(['tenants' => $tenants])),
        // Commons admin credentials for the S3 bucket + Meilisearch key.
        '*plex-admin*' => Process::result(output: base64_encode('larakube')),
        '*openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'CREATE DATABASE'),
        '*create configmap plex-registry*' => Process::result(output: 'configured'),
        '*' => Process::result(output: ''),
    ];
}

test('a full Commons Redis costs the tenant Redis only, not the whole join', function (): void {
    Process::fake(plexJoinRedisFullFakes());

    $project = plexJoinRedisFullProject();
    $previous = getcwd();
    chdir($project->path());

    $output = new BufferedOutput;

    try {
        $command = plexJoinRedisFullCommand($output, ['environment' => 'local', '--no-interaction' => true]);
        $exit = $command->handle();
    } finally {
        chdir($previous !== false ? $previous : sys_get_temp_dir());
    }

    $text = $output->fetch();

    // laraKubeWarn() renders through Termwind straight to STDOUT, so the
    // buffered output only carries the follow-up line — which is the one
    // naming the way out anyway.
    expect($exit)->toBe(0)
        ->and($text)->toContain('plex:evict')
        ->and($text)->toContain('Marked managed + plex in .larakube.json: postgres, meilisearch, seaweedfs');

    // The blueprint is the contract the deploy reads: Redis must NOT be
    // marked managed (or its Deployment would be patched away), while the
    // three services that DID join must be.
    $config = ConfigData::loadFromFile($project->path());

    expect($config->getManaged('local'))
        ->not->toContain('redis')
        ->toContain('postgres')
        ->and($config->getPlex('local'))->not->toContain('redis');

    // ...and .env keeps pointing Redis at the app's own pod.
    $env = (string) file_get_contents($project->path().'/.env');
    expect($env)->toContain('REDIS_HOST=redis.hey-statamic-local.svc.cluster.local');

    $project->delete();
});

test('a full Commons Redis still fails the join when Redis was the only service', function (): void {
    Process::fake(plexJoinRedisFullFakes());

    $dir = TemporaryDirectory::make();
    ConfigData::from([
        'name' => 'cache-only',
        'database' => 'sqlite',
        'cacheDriver' => 'redis',
        'environments' => ['local' => []],
    ])->saveToFile($dir->path());

    $previous = getcwd();
    chdir($dir->path());

    $output = new BufferedOutput;

    try {
        $command = plexJoinRedisFullCommand($output, ['environment' => 'local', '--no-interaction' => true]);
        $exit = $command->handle();
    } finally {
        chdir($previous !== false ? $previous : sys_get_temp_dir());
    }

    // Nothing to fall back to, so the join fails outright — and leaves no
    // half-written markers claiming a Commons the app never joined.
    expect($exit)->toBe(1)
        ->and(ConfigData::loadFromFile($dir->path())->getManaged('local'))->toBeEmpty();

    Process::assertDidntRun(fn ($process) => str_contains((string) $process->command, 'create configmap plex-registry'));

    $dir->delete();
});
