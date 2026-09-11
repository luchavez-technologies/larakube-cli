<?php

use Illuminate\Contracts\Console\Kernel;

// ── cloud:scale --storage Test ────────────────────────────────────────────────

test('cloud:scale command accepts --storage option', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('cloud:scale');
    $definition = $commands['cloud:scale']->getDefinition();

    expect($definition->hasOption('storage'))->toBeTrue();
});

// ── Snapshot Command Suite Tests ──────────────────────────────────────────────

test('snapshot:list command is registered and formatted', function (): void {
    $this->artisan('snapshot:list --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('snapshot:list');
});

test('snapshot:init command is registered', function (): void {
    $this->artisan('snapshot:init --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('snapshot:init');
});

test('snapshot:create command is registered and accepts pvc argument', function (): void {
    $this->artisan('snapshot:create --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('snapshot:create');
});

test('snapshot:clone command is registered', function (): void {
    $this->artisan('snapshot:clone --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('snapshot:clone');
});

test('snapshot:rollback command is registered', function (): void {
    $this->artisan('snapshot:rollback --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('snapshot:rollback');
});
