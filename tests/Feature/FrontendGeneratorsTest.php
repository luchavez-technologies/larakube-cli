<?php

use App\Commands\Astro\AstroNewCommand;
use App\Commands\Docs\DocsNewCommand;
use App\Commands\Vite\ViteNewCommand;

test('vite:new, astro:new, docs:new, and data:wire commands are registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('vite:new')
        ->expectsOutputToContain('astro:new')
        ->expectsOutputToContain('docs:new')
        ->expectsOutputToContain('data:wire');
});

test('vite:new command has template and ts options', function () {
    $command = app(ViteNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue();
    expect($definition->hasOption('ts'))->toBeTrue();
});

test('astro:new command has template option', function () {
    $command = app(AstroNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue();
});

test('docs:new command has template and typescript options', function () {
    $command = app(DocsNewCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('template'))->toBeTrue();
    expect($definition->hasOption('typescript'))->toBeTrue();
});
