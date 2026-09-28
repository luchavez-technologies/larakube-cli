<?php

/**
 * LaraKube Desktop creates an environment with no terminal, so every wizard
 * step needs a flag, and the server must never be guessed: a headless
 * select() would return the first kube-context on the machine.
 */

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function envHeadlessProject(): TemporaryDirectory
{
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($directory->path('.env'), "APP_NAME=env-headless\n");

    $config = ConfigData::from([
        'name' => 'env-headless',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => ['local' => []],
    ]);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setPath($directory->path());
    $config->saveToFile($directory->path());

    return $directory;
}

test('env creates an environment bound to the server named by --context, with no prompts', function (): void {
    $directory = envHeadlessProject();
    $previous = getcwd();
    chdir($directory->path());
    Prompt::interactive(false);
    Process::fake(['*' => Process::result(output: '')]);

    try {
        $this->artisan('env', [
            'name' => 'production',
            '--context' => 'larakube-203.0.113.21',
            '--web-host' => 'app.example.com',
            '--ingress' => 'traefik',
            '--managed' => '',
            '--web-hosts' => '',
            '--no-interaction' => true,
        ])
            // Under unit tests Laravel keeps prompts interactive, so the
            // defaulted questions a headless run skips are answered here.
            ->expectsConfirmation('Configure a container registry for production?', 'no')
            ->expectsQuestion('SSH user', 'larakube')
            ->expectsQuestion('SSH port', '22')
            ->expectsQuestion('SSH private key path', '~/.ssh/id_rsa')
            ->expectsConfirmation("Set up the CI/CD deploy workflow for 'production' now?", 'no')
            ->assertExitCode(0);

        $config = ConfigData::loadFromFile($directory->path());

        expect($config->getEnvironment('production')?->hosts)->toBe(['web' => 'app.example.com'])
            ->and($config->getCloud('production')?->ip)->toBe('203.0.113.21');
    } finally {
        chdir($previous);
    }
});

test('a headless env without --context stops instead of picking a server', function (): void {
    $directory = envHeadlessProject();
    $previous = getcwd();
    chdir($directory->path());
    Prompt::interactive(false);
    Process::fake(['*' => Process::result(output: "larakube-203.0.113.21\nlarakube-198.51.100.7")]);

    try {
        expect(fn () => $this->artisan('env', [
            'name' => 'production',
            '--ingress' => 'traefik',
            '--managed' => '',
            '--web-host' => '',
            '--web-hosts' => '',
            '--no-interaction' => true,
        ])->expectsConfirmation('Configure a container registry for production?', 'no')->run())->toThrow(InvalidArgumentException::class, '--context')
            ->and(ConfigData::loadFromFile($directory->path())->getCloud('production'))->toBeNull();
    } finally {
        chdir($previous);
    }
});
