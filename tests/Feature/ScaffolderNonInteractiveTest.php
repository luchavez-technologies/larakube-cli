<?php

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * There is no TTY behind these commands, and a create-* tool that reaches a
 * prompt exits 0 having produced nothing. `docs:new` hit exactly that: it
 * printed a ✓ spinner and then "scaffolding failed" in the same breath,
 * because create-docusaurus asked "Which language do you want to use?" and
 * exited successfully without writing anything.
 *
 * So every scaffolder invocation must be unambiguously non-interactive.
 */
/**
 * Every command the run records. Captured through the fake's own closure
 * rather than Process::assertRan(), which stops at the FIRST process whose
 * callback returns true — here that is `docker pull`, so the scaffolder
 * invocation was never inspected at all.
 *
 * @return list<string>
 */
function scaffolderCommands(callable $run): array
{
    $commands = [];

    Process::fake(['*' => function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result(output: '');
    }]);

    $run();

    return $commands;
}

/** @param  list<string>  $commands */
function scaffolderCommandFor(array $commands, string $tool): string
{
    foreach ($commands as $command) {
        if (str_contains($command, $tool)) {
            return $command;
        }
    }

    return '';
}

test('docs:new never lets create-docusaurus reach its language prompt', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $old = getcwd();
    chdir($dir->path());

    try {
        $commands = scaffolderCommands(fn () => $this->artisan('docs:new mydocs --no-interaction')->run());

        expect(scaffolderCommandFor($commands, 'create-docusaurus'))
            ->toContain('--javascript');
    } finally {
        chdir($old);
    }
});

test('docs:new --typescript picks the language explicitly too', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $old = getcwd();
    chdir($dir->path());

    try {
        $commands = scaffolderCommands(fn () => $this->artisan('docs:new mydocs --typescript --no-interaction')->run());

        expect(scaffolderCommandFor($commands, 'create-docusaurus'))
            ->toContain('--typescript');
    } finally {
        chdir($old);
    }
});

test('astro:new suppresses every prompt', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $old = getcwd();
    chdir($dir->path());

    try {
        $commands = scaffolderCommands(fn () => $this->artisan('astro:new mysite --no-interaction')->run());

        expect(scaffolderCommandFor($commands, 'create-astro'))
            ->toContain('--yes')
            ->toContain('--skip-houston');
    } finally {
        chdir($old);
    }
});

test('scaffolders run in Node, not on the host', function (): void {
    // Removes the host-Node dependency and matches vite:new.
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $old = getcwd();
    chdir($dir->path());

    try {
        $commands = scaffolderCommands(fn () => $this->artisan('docs:new mydocs --no-interaction')->run());

        expect(scaffolderCommandFor($commands, 'create-docusaurus'))
            ->toContain('docker run')
            ->toContain('node:24-alpine');
    } finally {
        chdir($old);
    }
});
