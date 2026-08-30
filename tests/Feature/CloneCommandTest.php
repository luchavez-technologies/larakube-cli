<?php

use Illuminate\Contracts\Console\Kernel;

test('clone command is registered and accepts shorthand repo URLs', function (): void {
    $this->artisan('clone --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('clone');
});

test('clone command signature has directory and provider options', function (): void {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('clone');
    $definition = $commands['clone']->getDefinition();

    expect($definition->hasArgument('repo'))->toBeTrue()
        ->and($definition->hasOption('directory'))->toBeTrue()
        ->and($definition->hasOption('branch'))->toBeTrue()
        ->and($definition->hasOption('provider'))->toBeTrue()
        ->and($definition->hasOption('no-install'))->toBeTrue();
});
