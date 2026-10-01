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

test('env respects --ssh-key, --ssh-user, and --ssh-port without asking', function (): void {
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
            '--ssh-key' => '/custom/key',
            '--ssh-user' => 'custom-user',
            '--ssh-port' => '2222',
            '--no-interaction' => true,
        ])
            ->expectsConfirmation('Configure a container registry for production?', 'no')
            ->expectsConfirmation("Set up the CI/CD deploy workflow for 'production' now?", 'no')
            ->assertExitCode(0);

        $config = ConfigData::loadFromFile($directory->path());

        expect($config->getCloud('production')?->key)->toBe('/custom/key')
            ->and($config->getCloud('production')?->user)->toBe('custom-user')
            ->and($config->getCloud('production')?->port)->toBe(2222);
    } finally {
        chdir($previous);
    }
});

test('env auto-resolves SSH key and user from ~/.ssh/config when context matches', function (): void {
    $directory = envHeadlessProject();
    $previous = getcwd();
    chdir($directory->path());
    Prompt::interactive(false);
    Process::fake(['*' => Process::result(output: '')]);

    @mkdir(home_path('.ssh'), 0700, true);
    $keyPath = home_path('.ssh/resolved_key');
    file_put_contents($keyPath, 'fake-private-key');
    file_put_contents(home_path('.ssh/config'), "Host test-vps\n    HostName 203.0.113.88\n    User resolved-user\n    Port 2200\n    IdentityFile {$keyPath}\n");

    try {
        $this->artisan('env', [
            'name' => 'production',
            '--context' => 'larakube-203.0.113.88',
            '--web-host' => 'app.example.com',
            '--ingress' => 'traefik',
            '--managed' => '',
            '--web-hosts' => '',
            '--no-interaction' => true,
        ])
            ->expectsConfirmation('Configure a container registry for production?', 'no')
            ->expectsQuestion('SSH user', 'resolved-user')
            ->expectsQuestion('SSH port', '2200')
            ->expectsQuestion('SSH private key path', $keyPath)
            ->expectsConfirmation("Set up the CI/CD deploy workflow for 'production' now?", 'no')
            ->assertExitCode(0);

        $config = ConfigData::loadFromFile($directory->path());

        expect($config->getCloud('production')?->key)->toBe($keyPath)
            ->and($config->getCloud('production')?->user)->toBe('resolved-user')
            ->and($config->getCloud('production')?->port)->toBe(2200);
    } finally {
        chdir($previous);
    }
});
