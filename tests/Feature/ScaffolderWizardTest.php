<?php

use App\Traits\ScaffoldsInNode;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * The scaffolder's OWN wizard is the wizard.
 *
 * `larakube new` hands off to the official `laravel new` installer and inherits
 * whatever it asks, so Laravel's updates arrive for free. vite/astro/docs now
 * work the same way: create-vite, create-astro and create-docusaurus each
 * maintain their own prompts, and mirroring those options in a LaraKube-side
 * wizard would mean shipping a list that silently rots every time upstream adds
 * a template — while asking questions upstream may since have reworded.
 *
 * That delegation needs a real terminal, and the fallback is not optional: a
 * create-* tool that reaches a prompt with no TTY exits 0 having produced
 * nothing. create-docusaurus did exactly that, so `docs:new` printed a ✓
 * spinner and "scaffolding failed" in the same breath. Hence two command lines
 * per scaffolder, and the rule these tests hold: the scripted one must answer
 * every question upstream would have asked.
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

function wizardHolder(): object
{
    return new class
    {
        use ScaffoldsInNode;
    };
}

test('the terminal is handed over only when there is one to hand over', function (bool $noInteraction, bool $fast, bool $tty, bool $expected): void {
    expect(wizardHolder()->promptCapable($noInteraction, $fast, $tty))->toBe($expected);
})->with([
    // The default: a real terminal, no opt-out — upstream asks its own questions.
    'plain interactive run' => [false, false, true, true],
    // `docker run -it` fails outright without a TTY, so this is a hard
    // capability question, not a preference.
    'piped or CI, no tty' => [false, false, false, false],
    'no-interaction' => [true, false, true, false],
    'fast' => [false, true, true, false],
    'fast without a tty' => [false, true, false, false],
]);

test('the scripted command line answers every question upstream would ask', function (string $command, string $tool, array $mustContain): void {
    wizardInTempDir(function () use ($command, $tool, $mustContain): void {
        // Tests never get the terminal, so this is always the scripted path —
        // which is exactly the one that has to be airtight.
        $commands = wizardCommands(fn () => $this->artisan($command)->run());
        $line = wizardCommandFor($commands, $tool);

        foreach ($mustContain as $fragment) {
            expect($line)->toContain($fragment);
        }
    });
})->with([
    'vite --fast' => ['vite:new myapp --fast', 'create-vite', ['--template', 'react-ts']],
    'astro --fast' => ['astro:new mysite --fast', 'create-astro', ['--template', 'minimal', '--yes']],
    // A language flag is the one that is genuinely load-bearing: without it
    // create-docusaurus exits 0 having created nothing.
    'docs --fast' => ['docs:new mydocs --fast', 'create-docusaurus', ['classic', '--typescript']],
    'docs plain' => ['docs:new mydocs --no-interaction', 'create-docusaurus', ['classic', '--javascript']],
    'docs --typescript' => ['docs:new mydocs --no-interaction --typescript', 'create-docusaurus', ['--typescript']],
]);

test('an explicit --template is passed through rather than validated against a list', function (string $command, string $tool, string $expected): void {
    wizardInTempDir(function () use ($command, $tool, $expected): void {
        $commands = wizardCommands(fn () => $this->artisan($command)->run());

        expect(wizardCommandFor($commands, $tool))->toContain($expected);
    });
})->with([
    // Previously each command validated against a hardcoded list and silently
    // fell back to its default, so a template upstream had added but LaraKube
    // had not heard of was impossible to ask for. Whether a template exists is
    // upstream's question to answer, not ours.
    'vite' => ['vite:new myapp --template=svelte-ts --no-interaction', 'create-vite', 'svelte-ts'],
    'astro' => ['astro:new mysite --template=starlight --no-interaction', 'create-astro', 'starlight'],
    'docs' => ['docs:new mydocs --template=facebook --no-interaction', 'create-docusaurus', 'facebook'],
]);

test('no LaraKube-side prompt stands between the user and the scaffolder', function (string $command): void {
    wizardInTempDir(function () use ($command): void {
        // No expectsChoice/expectsConfirmation stubs at all, so reaching any
        // prompt throws. That is the assertion: the questions belong upstream.
        wizardCommands(fn () => $this->artisan($command)->run());
    });

    expect(true)->toBeTrue();
})->with([
    'vite' => ['vite:new myapp --no-interaction'],
    'astro' => ['astro:new mysite --no-interaction'],
    'docs' => ['docs:new mydocs --no-interaction'],
]);
