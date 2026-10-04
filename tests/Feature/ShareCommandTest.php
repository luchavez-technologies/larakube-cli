<?php

use App\Data\ConfigData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Runs $test with a LaraKube project as the working folder, then goes back.
 */
function inShareProject(callable $test): void
{
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $project = $dir->path('shop');
    File::ensureDirectoryExists($project);
    file_put_contents($project.'/.larakube.json', json_encode((new ConfigData(name: 'shop'))->toArray()));

    $previous = getcwd();
    chdir($project);

    try {
        $test();
    } finally {
        chdir($previous);
    }
}

/** The command's JSON result: the last line of its output, since the buffer under test also holds the log lines. */
function shareJsonResult(): ?array
{
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

/** A cluster where each share pod's log carries its quick-tunnel address. */
function shareFakeCluster(?array &$commands): void
{
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $commands[] = $cmd;

        if (str_contains($cmd, ' logs ')) {
            return Process::result(output: "INF +----------------------------+\nINF |  https://random-words-here.trycloudflare.com  |\n");
        }

        return Process::result(output: '');
    });
}

test('share --json starts the tunnels, reports the public link, and leaves without waiting', function (): void {
    shareFakeCluster($commands);
    putenv('CLOUDFLARE_TUNNEL_TOKEN');

    inShareProject(function (): void {
        $exit = Artisan::call('share', ['--json' => true, '--no-interaction' => true]);
        $result = shareJsonResult();

        expect($exit)->toBe(0)
            ->and($result)->toMatchArray(['success' => true, 'mode' => 'quick'])
            ->and($result['urls']['web'])->toBe('https://random-words-here.trycloudflare.com');
    });
});

test('share --stop --json takes the tunnels down and says so', function (): void {
    shareFakeCluster($commands);

    inShareProject(function () use (&$commands): void {
        $exit = Artisan::call('share', ['--stop' => true, '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0)
            ->and(shareJsonResult())->toBe(['success' => true, 'stopped' => true])
            ->and(implode("\n", $commands))->toContain('delete deployment -l larakube.dev/role=share');
    });
});

test('share --json outside a LaraKube project fails with a reason a program can read', function (): void {
    Process::fake();
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $previous = getcwd();
    chdir($dir->path());

    try {
        $exit = Artisan::call('share', ['--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(1)
            ->and(shareJsonResult())->toBe(['success' => false, 'error' => 'This folder is not a LaraKube project.']);
    } finally {
        chdir($previous);
    }
});
