<?php

use App\Data\ConfigData;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** A project with a staging environment, optionally on Plex Commons, in a temp directory. */
function envDestroyProject(array $plex = []): array
{
    $config = ConfigData::from([
        'name' => 'envdestroy',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'environments' => [
            'local' => [],
            'staging' => ['hosts' => ['web' => 'stg.example.com'], 'plex' => $plex],
        ],
    ]);
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $config->setPath($directory->path());
    $config->saveToFile($directory->path());

    return [$directory->path(), $directory];
}

/**
 * Stands in for cloud:nuke, plex:evict and env:remove, recording each call in order, so the test
 * sees the sequence env:destroy runs without touching a cluster.
 *
 * @param  array<string, int>  $exitCodes
 */
function envDestroyFakeSteps(array &$calls, array $exitCodes = []): void
{
    foreach (['cloud:nuke' => '{environment?} {--force}', 'plex:evict' => '{environment?} {--tenant=} {--force}', 'env:remove' => '{environment?} {--force}'] as $name => $arguments) {
        app(Kernel::class)->registerCommand(new class($name, $arguments, $calls, $exitCodes[$name] ?? 0) extends Command
        {
            public function __construct(string $name, string $arguments, private array &$calls, private int $exit)
            {
                $this->signature = "{$name} {$arguments}";
                parent::__construct();
            }

            public function handle(): int
            {
                $this->calls[] = trim($this->getName().' '.$this->argument('environment').' '.($this->hasOption('tenant') ? (string) $this->option('tenant') : ''));

                return $this->exit;
            }
        });
    }
}

function envDestroyIn(string $directory, callable $run): void
{
    $original = getcwd();
    chdir($directory);
    try {
        $run();
    } finally {
        chdir($original);
    }
}

test('env:destroy tears down the server, then forgets the environment', function (): void {
    [$path, $directory] = envDestroyProject();
    $calls = [];
    envDestroyFakeSteps($calls);

    envDestroyIn($path, fn () => $this->artisan('env:destroy staging --force')
        ->expectsOutputToContain("'staging' is deleted")
        ->assertExitCode(0));

    expect($calls)->toBe(['cloud:nuke staging', 'env:remove staging']);
});

test('env:destroy also removes the environment\'s Plex Commons data, between the two', function (): void {
    [$path, $directory] = envDestroyProject(['postgres']);
    $calls = [];
    envDestroyFakeSteps($calls);

    envDestroyIn($path, fn () => $this->artisan('env:destroy staging --force')->assertExitCode(0));

    expect($calls)->toBe(['cloud:nuke staging', 'plex:evict staging envdestroy_staging', 'env:remove staging']);
});

test('env:destroy stops at a failed teardown and keeps the environment so it can run again', function (): void {
    [$path, $directory] = envDestroyProject(['postgres']);
    $calls = [];
    envDestroyFakeSteps($calls, ['cloud:nuke' => 1]);

    envDestroyIn($path, fn () => $this->artisan('env:destroy staging --force')
        ->expectsOutputToContain('run larakube env:destroy staging again')
        ->assertExitCode(1));

    expect($calls)->toBe(['cloud:nuke staging']);
});

test('env:destroy refuses local and unknown environments', function (): void {
    [$path, $directory] = envDestroyProject();
    $calls = [];
    envDestroyFakeSteps($calls);

    envDestroyIn($path, function (): void {
        $this->artisan('env:destroy local --force')->assertExitCode(1);
        $this->artisan('env:destroy production --force')->assertExitCode(1);
    });

    expect($calls)->toBeEmpty();
});
