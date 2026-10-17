<?php

use Illuminate\Support\Facades\Process;

function wslCmdUtf16(string $text): string
{
    return "\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
}

function wslCmdListing(string ...$lines): string
{
    return wslCmdUtf16("  NAME            STATE           VERSION\n".implode("\n", $lines)."\n");
}

test('wsl:status outputs environment status table', function (): void {
    Process::fake([
        '*--status*' => Process::result(output: wslCmdUtf16('Default Version: 2')),
        '*--list*--quiet*' => Process::result(output: wslCmdUtf16("larakube-ubuntu\n")),
        '*--list*--running*' => Process::result(output: wslCmdListing('* larakube-ubuntu Running   2')),
        '*wslinfo*--networking-mode*' => Process::result(output: "mirrored\n"),
    ]);

    $this->artisan('wsl:status')
        ->assertExitCode(0)
        ->expectsOutputToContain('WSL2 Environment Status');
});

test('wsl:restart restarts WSL when no other distros are running', function (): void {
    Process::fake([
        '*--status*' => Process::result(output: wslCmdUtf16('Default Version: 2')),
        '*--list*--running*' => Process::result(output: wslCmdListing('* larakube-ubuntu Running   2')),
        '*--shutdown*' => Process::result(),
    ]);

    $this->artisan('wsl:restart')
        ->assertExitCode(0)
        ->expectsOutputToContain('WSL shut down successfully');

    Process::assertRan(fn ($p) => in_array('--shutdown', (array) $p->command, true));
});

test('wsl:restart with --force bypasses prompt when other distros are running', function (): void {
    Process::fake([
        '*--status*' => Process::result(output: wslCmdUtf16('Default Version: 2')),
        '*--list*--running*' => Process::result(output: wslCmdListing('* larakube-ubuntu Running   2', '  Ubuntu Running 2')),
        '*--shutdown*' => Process::result(),
    ]);

    $this->artisan('wsl:restart --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('WSL shut down successfully');

    Process::assertRan(fn ($p) => in_array('--shutdown', (array) $p->command, true));
});

test('wsl:shutdown terminates WSL virtual machine', function (): void {
    Process::fake([
        '*--status*' => Process::result(output: wslCmdUtf16('Default Version: 2')),
        '*--list*--running*' => Process::result(output: wslCmdListing('* larakube-ubuntu Running   2')),
        '*--shutdown*' => Process::result(),
    ]);

    $this->artisan('wsl:shutdown --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('WSL shut down successfully');

    Process::assertRan(fn ($p) => in_array('--shutdown', (array) $p->command, true));
});
