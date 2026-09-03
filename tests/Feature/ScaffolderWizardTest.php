<?php

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * `vite:new`, `astro:new` and `docs:new` each advertised `--fast : Skip the
 * wizard` while never reading the flag and never asking anything — every
 * choice but the project name came from a silent flag default. So `larakube
 * new` (which proxies to the official `laravel new` installer and inherits its
 * questions) and `nextjs:new` (which has its own wizard) both asked, and these
 * three did not.
 *
 * The upstream scaffolders are deliberately run non-interactively — they run
 * inside a container, where a prompt hangs or, in create-docusaurus's case,
 * exits 0 having produced nothing. That is exactly why the questions have to
 * be asked HERE, before the container starts.
 */

/** @return list<string> */
function wizardCommands(callable $run): array
{
    $commands = [];

    Process::fake(['*' => function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result(output: '');
    }]);

    $run();

    return $commands;
}

function wizardCommandFor(array $commands, string $tool): string
{
    foreach ($commands as $command) {
        if (str_contains($command, $tool)) {
            return $command;
        }
    }

    return '';
}

function wizardInTempDir(callable $body): void
{
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $old = getcwd();
    chdir($dir->path());

    try {
        $body();
    } finally {
        chdir($old);
    }
}

test('vite:new asks for framework, language and package manager', function (): void {
    wizardInTempDir(function (): void {
        $commands = wizardCommands(fn () => $this->artisan('vite:new myapp')
            ->expectsChoice('Which framework would you like to use?', 'vue', [
                'react' => 'React (Recommended)',
                'vue' => 'Vue',
                'svelte' => 'Svelte',
                'solid' => 'Solid',
                'vanilla' => 'Vanilla (no framework)',
            ])
            ->expectsConfirmation('Use TypeScript?', 'no')
            ->expectsChoice('Which package manager?', 'pnpm', [
                'npm' => 'npm (Recommended)',
                'pnpm' => 'pnpm',
                'bun' => 'bun',
                'yarn' => 'yarn',
            ])
            ->run());

        // The answers have to reach create-vite as flags — it is run
        // non-interactively, so anything not passed is silently defaulted.
        expect(wizardCommandFor($commands, 'create-vite'))
            ->toContain('--template vue')
            ->not->toContain('vue-ts');
    });
});

test('vite:new turns a yes on TypeScript into the -ts template id', function (): void {
    wizardInTempDir(function (): void {
        $commands = wizardCommands(fn () => $this->artisan('vite:new myapp')
            ->expectsChoice('Which framework would you like to use?', 'react', [
                'react' => 'React (Recommended)',
                'vue' => 'Vue',
                'svelte' => 'Svelte',
                'solid' => 'Solid',
                'vanilla' => 'Vanilla (no framework)',
            ])
            ->expectsConfirmation('Use TypeScript?', 'yes')
            ->expectsChoice('Which package manager?', 'npm', [
                'npm' => 'npm (Recommended)',
                'pnpm' => 'pnpm',
                'bun' => 'bun',
                'yarn' => 'yarn',
            ])
            ->run());

        // create-vite has no --ts flag; the TS variants are separate ids.
        expect(wizardCommandFor($commands, 'create-vite'))->toContain('--template react-ts');
    });
});

test('astro:new asks which starter', function (): void {
    wizardInTempDir(function (): void {
        $commands = wizardCommands(fn () => $this->artisan('astro:new mysite')
            ->expectsChoice('Which Astro starter would you like?', 'blog', [
                'minimal' => 'Minimal — an empty starter (Recommended)',
                'blog' => 'Blog — posts, RSS and a content collection',
                'portfolio' => 'Portfolio — a personal site',
                'docs' => 'Docs — Starlight documentation',
            ])
            ->run());

        expect(wizardCommandFor($commands, 'create-astro'))->toContain('--template blog');
    });
});

test('docs:new asks for template and language', function (): void {
    wizardInTempDir(function (): void {
        $commands = wizardCommands(fn () => $this->artisan('docs:new mydocs')
            ->expectsChoice('Which Docusaurus template would you like?', 'classic', [
                'classic' => 'Classic — docs, blog and pages (Recommended)',
                'facebook' => 'Facebook — the internal-style preset',
            ])
            ->expectsConfirmation('Use TypeScript?', 'yes')
            ->run());

        expect(wizardCommandFor($commands, 'create-docusaurus'))->toContain('--typescript');
    });
});

test('an explicit --template skips the wizard entirely', function (string $command, string $tool, string $expected): void {
    wizardInTempDir(function () use ($command, $tool, $expected): void {
        // No expectsChoice/expectsConfirmation stubs at all: reaching any
        // prompt here throws, which is the assertion. A caller who has already
        // stated the template should not then be half-interviewed.
        $commands = wizardCommands(fn () => $this->artisan($command)->run());

        expect(wizardCommandFor($commands, $tool))->toContain($expected);
    });
})->with([
    'vite' => ['vite:new myapp --template=svelte', 'create-vite', '--template svelte'],
    'astro' => ['astro:new mysite --template=docs', 'create-astro', '--template docs'],
    'docs' => ['docs:new mydocs --template=facebook', 'create-docusaurus', 'facebook'],
]);

test('--fast skips the wizard and uses the recommended defaults', function (string $command, string $tool, string $expected): void {
    wizardInTempDir(function () use ($command, $tool, $expected): void {
        $commands = wizardCommands(fn () => $this->artisan($command)->run());

        expect(wizardCommandFor($commands, $tool))->toContain($expected);
    });
})->with([
    'vite' => ['vite:new myapp --fast', 'create-vite', '--template react-ts'],
    'astro' => ['astro:new mysite --fast', 'create-astro', '--template minimal'],
    'docs' => ['docs:new mydocs --fast', 'create-docusaurus', '--typescript'],
]);
