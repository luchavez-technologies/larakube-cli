<?php

use App\Commands\Statamic\StatamicDatabaseCommand;
use App\Data\ConfigData;
use App\Enums\AppFramework;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** A Statamic 6 skeleton's user/auth files, as the documented edits expect them. */
function statamicDatabaseSkeleton(string $dir, bool $kitChangedAuth = false): void
{
    @mkdir("{$dir}/config/statamic", 0777, true);
    @mkdir("{$dir}/app/Models", 0777, true);
    file_put_contents("{$dir}/config/statamic/users.php", "<?php\n\nreturn [\n    'repository' => 'file',\n];\n");
    file_put_contents("{$dir}/config/auth.php", $kitChangedAuth
        ? "<?php\n\nreturn ['providers' => ['users' => ['driver' => 'custom']]];\n"
        : "<?php\n\nreturn [\n    'providers' => [\n        'users' => [\n            'driver' => 'statamic',\n        ],\n    ],\n];\n");
    file_put_contents("{$dir}/app/Models/User.php", "<?php\n\nclass User\n{\n    protected function casts(): array\n    {\n        return [\n            'email_verified_at' => 'datetime',\n            'password' => 'hashed',\n        ];\n    }\n}\n");
}

/** The command with a console bound, for calling its trait methods directly. */
function statamicDatabaseCommand(): StatamicDatabaseCommand
{
    $command = app(StatamicDatabaseCommand::class);
    $input = new Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, new Symfony\Component\Console\Output\BufferedOutput));

    return $command;
}

test('users move to the database with exactly the documented edits', function (): void {
    $directory = TemporaryDirectory::make();
    statamicDatabaseSkeleton($directory->path());

    $failed = (new ReflectionMethod(StatamicDatabaseCommand::class, 'useDatabaseUsers'))->invoke(statamicDatabaseCommand(), $directory->path());

    expect($failed)->toBe([])
        ->and(file_get_contents($directory->path('config/statamic/users.php')))->toContain("'repository' => 'eloquent',")
        ->and(file_get_contents($directory->path('config/auth.php')))->toContain("'driver' => 'eloquent',\n            'model' => App\\Models\\User::class,")
        ->and(file_get_contents($directory->path('app/Models/User.php')))
        ->toContain("'preferences' => 'json',")
        ->toContain("'two_factor_confirmed_at' => 'datetime',")
        ->toContain('new \\Statamic\\Notifications\\PasswordReset($token)');
    $directory->delete();
});

test('a file that differs from the skeleton is reported and nothing is edited', function (): void {
    $directory = TemporaryDirectory::make();
    statamicDatabaseSkeleton($directory->path(), kitChangedAuth: true);

    $failed = (new ReflectionMethod(StatamicDatabaseCommand::class, 'useDatabaseUsers'))->invoke(statamicDatabaseCommand(), $directory->path());

    expect($failed)->toBe(['config/auth.php'])
        // All or nothing: the untouched files keep their file-user settings.
        ->and(file_get_contents($directory->path('config/statamic/users.php')))->toContain("'repository' => 'file',")
        ->and(file_get_contents($directory->path('app/Models/User.php')))->not->toContain('preferences');
    $directory->delete();
});

test('the pod steps run in order and the password reaches PHP on stdin, never argv', function (): void {
    $execs = [];
    Process::fake(function ($process) use (&$execs) {
        $command = (string) $process->command;
        if (str_contains($command, 'get pods -n shop-local -l app=web')) {
            return Process::result(output: 'web-abc123');
        }
        if (str_contains($command, ' exec ')) {
            $execs[] = ['command' => $command, 'stdin' => $process->input];
        }

        return Process::result(output: '');
    });

    $config = new ConfigData(name: 'shop');
    $config->framework = AppFramework::STATAMIC;

    $ok = (new ReflectionMethod(StatamicDatabaseCommand::class, 'setUpStatamicDatabase'))
        ->invoke(statamicDatabaseCommand(), $config, 'local', ['email' => 'owner@example.com', 'password' => 'Tr0ub4dour&3-horse']);

    expect($ok)->toBeTrue()
        ->and(array_map(fn (array $e) => preg_match('/install:eloquent-driver|auth:migration|artisan migrate|php -r/', $e['command'], $m) ? $m[0] : null, $execs))
        ->toBe(['install:eloquent-driver', 'auth:migration', 'artisan migrate', 'php -r'])
        ->and($execs[0]['command'])->toContain('exec -n shop-local web-abc123 -c php')
        ->and($execs[0]['command'])->toContain('--all --import')
        ->and(json_decode((string) $execs[3]['stdin'], true))->toBe(['email' => 'owner@example.com', 'password' => 'Tr0ub4dour&3-horse']);
    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'Tr0ub4dour'));
});

test('without a running web pod it says to run up first, and touches nothing', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    $config = new ConfigData(name: 'shop');
    $config->framework = AppFramework::STATAMIC;

    $ok = (new ReflectionMethod(StatamicDatabaseCommand::class, 'setUpStatamicDatabase'))->invoke(statamicDatabaseCommand(), $config, 'local', null);

    expect($ok)->toBeFalse();
    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, ' exec '));
});

test('statamic:database refuses outside a Statamic project', function (): void {
    $directory = TemporaryDirectory::make();
    (new ConfigData(name: 'plain'))->saveToFile($directory->path());
    $original = getcwd();
    chdir($directory->path());

    try {
        $this->artisan('statamic:database --no-interaction')
            ->expectsOutputToContain('Run this from a Statamic project')
            ->assertExitCode(1);
    } finally {
        chdir($original);
        $directory->delete();
    }
});
