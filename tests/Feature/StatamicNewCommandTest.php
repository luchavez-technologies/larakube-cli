<?php

use App\Enums\AppFramework;
use Illuminate\Contracts\Console\Kernel;
use Spatie\TemporaryDirectory\TemporaryDirectory;

// ── AppFramework Enum Tests ──────────────────────────────────────────────────

test('AppFramework has the expected cases', function (): void {
    expect(AppFramework::cases())->toHaveCount(15)
        ->and(AppFramework::LARAVEL->value)->toBe('laravel')
        ->and(AppFramework::STATAMIC->value)->toBe('statamic')
        ->and(AppFramework::WORDPRESS->value)->toBe('wordpress')
        ->and(AppFramework::NEXTJS->value)->toBe('nextjs');
});

test('AppFramework::getLabel returns human-readable names', function (): void {
    expect(AppFramework::LARAVEL->getLabel())->toBe('Laravel')
        ->and(AppFramework::STATAMIC->getLabel())->toBe('Statamic')
        ->and(AppFramework::WORDPRESS->getLabel())->toBe('WordPress (Bedrock)')
        ->and(AppFramework::NEXTJS->getLabel())->toBe('Next.js');
});

test('AppFramework::healthProbePath returns correct paths', function (): void {
    expect(AppFramework::LARAVEL->healthProbePath())->toBe('/up')
        ->and(AppFramework::STATAMIC->healthProbePath())->toBe('/up')
        ->and(AppFramework::WORDPRESS->healthProbePath())->toBe('/wp/wp-includes/version.php')
        ->and(AppFramework::NEXTJS->healthProbePath())->toBe('/api/health');
});

test('AppFramework::detect returns STATAMIC when statamic/cms in composer.json', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    touch("$dir/artisan");
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['statamic/cms' => '^5.0', 'laravel/framework' => '^12.0'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::STATAMIC);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns WORDPRESS when wp-config.php present', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    touch("$dir/wp-config.php");

    expect(AppFramework::detect($dir))->toBe(AppFramework::WORDPRESS);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns WORDPRESS when roots/bedrock in composer.json', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['roots/bedrock' => '*'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::WORDPRESS);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns NEXTJS when next.config.ts present', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    touch("$dir/next.config.ts");

    expect(AppFramework::detect($dir))->toBe(AppFramework::NEXTJS);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns LARAVEL for a plain Laravel project', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    touch("$dir/artisan");
    file_put_contents("$dir/composer.json", json_encode([
        'require' => ['laravel/framework' => '^12.0'],
    ]));

    expect(AppFramework::detect($dir))->toBe(AppFramework::LARAVEL);

    $temporaryDirectory->delete();
});

test('AppFramework::detect returns null for an unknown directory', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    expect(AppFramework::detect($dir))->toBeNull();

    $temporaryDirectory->delete();
});

// ── statamic:new Command Tests ───────────────────────────────────────────────

test('statamic:new command is registered and has correct signature', function (): void {
    $this->artisan('statamic:new --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('statamic:new');
});

test('statamic:new command has --fast option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('statamic:new')
        ->and($commands['statamic:new']->getDefinition()->hasOption('fast'))->toBeTrue();
});

// ── Scaffolding with the official Statamic CLI ──────────────────────────────

/**
 * A statamic:new instance with its options bound, and the site dir the fake
 * installer "creates" (with $composer and a bun.lock) when `statamic new` runs.
 *
 * @return array{0: App\Commands\Statamic\StatamicNewCommand, 1: string, 2: TemporaryDirectory}
 */
function statamicInstaller(array $options = [], ?string $kit = null, ?array $superUser = null, array $composer = ['require' => ['php' => '^8.2']]): array
{
    $command = app(App\Commands\Statamic\StatamicNewCommand::class);
    $input = new Symfony\Component\Console\Input\ArrayInput($options, $command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, new Symfony\Component\Console\Output\BufferedOutput));

    foreach (['starterKit' => $kit, 'superUser' => $superUser] as $property => $value) {
        (new ReflectionProperty($command, $property))->setValue($command, $value);
    }

    $directory = TemporaryDirectory::make();
    $base = $directory->path();

    Illuminate\Support\Facades\Process::fake(function ($process) use ($base, $composer) {
        if (str_contains((string) $process->command, 'statamic new site')) {
            @mkdir("{$base}/site", 0777, true);
            file_put_contents("{$base}/site/composer.json", json_encode($composer));
            file_put_contents("{$base}/site/bun.lock", '');
            file_put_contents("{$base}/site/vite.config.js", 'export default {}');
        }

        return Illuminate\Support\Facades\Process::result(output: '');
    });

    return [$command, $base, $directory];
}

function statamicConfig(): App\Data\ConfigData
{
    $config = new App\Data\ConfigData(name: 'site');
    $config->framework = AppFramework::STATAMIC;
    $config->serverVariation = App\Enums\ServerVariation::FPM_NGINX;
    $config->phpVersion = App\Enums\PhpVersion::PHP_8_4;
    $config->setPackageManager(App\Enums\PackageManager::NPM);

    return $config;
}

test('statamic:new installs the official CLI and passes the kit and its flags, after the PHP extensions', function (): void {
    [$command, $base, $directory] = statamicInstaller(['--pro' => true, '--license' => 'KIT-KEY'], 'jasonbaciulis/bedrock');

    (new ReflectionMethod($command, 'runStatamicNew'))->invoke($command, 'site', statamicConfig(), $base);

    Illuminate\Support\Facades\Process::assertRan(function ($process): bool {
        $cmd = (string) $process->command;
        $extensions = strpos($cmd, 'install-php-extensions gd exif');
        $cli = strpos($cmd, 'composer global require statamic/cli:^3.6');
        $new = strpos($cmd, 'statamic new site jasonbaciulis/bedrock --pro --license=KIT-KEY --no-interaction --no-ascii-art');

        return $extensions !== false && $cli !== false && $new !== false
            && $extensions < $cli && $cli < $new
            && str_contains($cmd, 'npm install -g bun');
    });
    $directory->delete();
});

test('the super user password reaches the container as an environment variable, never argv', function (): void {
    [$command, $base, $directory] = statamicInstaller(superUser: ['email' => 'owner@example.com', 'password' => 'Tr0ub4dour&3-horse']);

    (new ReflectionMethod($command, 'runStatamicNew'))->invoke($command, 'site', statamicConfig(), $base);

    Illuminate\Support\Facades\Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'Tr0ub4dour'));
    Illuminate\Support\Facades\Process::assertRan(fn ($process) => str_contains((string) $process->command, 'php .larakube-super-user.php')
        && ($process->environment['LARAKUBE_SU_PASSWORD'] ?? null) === 'Tr0ub4dour&3-horse'
        && ($process->environment['LARAKUBE_SU_EMAIL'] ?? null) === 'owner@example.com');
    expect(file_exists("{$base}/site/.larakube-super-user.php"))->toBeFalse();
    $directory->delete();
});

test('a starter kit\'s package manager, PHP floor and Vite front end are adopted after install', function (): void {
    [$command, $base, $directory] = statamicInstaller(composer: ['require' => ['php' => '^8.5']]);
    $config = statamicConfig();

    (new ReflectionMethod($command, 'runStatamicNew'))->invoke($command, 'site', $config, $base);
    (new ReflectionMethod($command, 'adoptProjectRequirements'))->invoke($command, $config, "{$base}/site");

    expect($config->getPackageManager())->toBe(App\Enums\PackageManager::BUN)
        ->and($config->phpVersion)->toBe(App\Enums\PhpVersion::PHP_8_5)
        // A Vite-built site gets the local dev server pod, i.e. HMR.
        ->and($config->getFrontend())->toBe(App\Enums\FrontendStack::VITE)
        ->and($config->getFrontend()->requiresNodePod())->toBeTrue();
    $directory->delete();
});

test('a weak super user password is refused, because it ships to production', function (string $password, ?string $reason): void {
    expect(App\Commands\Statamic\StatamicNewCommand::weakPasswordReason($password, 'owner@example.com'))->toBe($reason);
})->with([
    ['short', 'Use at least 12 characters.'],
    ['password1234', 'That password is too common.'],
    ['owner-secret-99', "Don't include your email name in the password."],
    ['aaaaaaaaaaab', 'Use more varied characters.'],
    ['Tr0ub4dour&3-horse', null],
]);

test('a starter kit must be named vendor/kit', function (): void {
    [$command, , $directory] = statamicInstaller(['--starter-kit' => 'bedrock; rm -rf /']);

    try {
        (new ReflectionMethod($command, 'resolveStarterKit'))->invoke($command);
    } finally {
        $directory->delete();
    }
})->throws(InvalidArgumentException::class, 'use vendor/kit');

test('the installer runs in a terminal session without crashing', function (): void {
    // Tests have no TTY, so the interactive path went unexercised and called a
    // method that doesn't exist on Laravel's Process.
    [$command, , $directory] = statamicInstaller();

    (new ReflectionMethod($command, 'runInstaller'))->invoke($command, 'docker run --rm -it builder statamic new site', true);

    Illuminate\Support\Facades\Process::assertRan(fn ($process) => str_contains((string) $process->command, 'statamic new site'));
    $directory->delete();
});

test('content lives in the database by default, and --content only accepts database or files', function (): void {
    [$command, , $directory] = statamicInstaller(['--fast' => true]);
    $resolve = new ReflectionMethod($command, 'resolveContentStorage');

    expect($resolve->invoke($command))->toBe('database');

    [$files, , $second] = statamicInstaller(['--content' => 'files']);
    expect($resolve->invoke($files))->toBe('files');

    [$bad, , $third] = statamicInstaller(['--content' => 'mongo']);
    try {
        $resolve->invoke($bad);
    } finally {
        foreach ([$directory, $second, $third] as $d) {
            $d->delete();
        }
    }
})->throws(InvalidArgumentException::class, '--content must be');
