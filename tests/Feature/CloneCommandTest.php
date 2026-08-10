<?php

use Illuminate\Contracts\Console\Kernel;

test('clone command is registered and accepts shorthand repo URLs', function () {
    $this->artisan('clone --help')
        ->assertExitCode(0)
        ->expectsOutputToContain('clone');
});

test('clone command signature has directory and provider options', function () {
    $kernel = app(Kernel::class);
    $commands = $kernel->all();

    expect($commands)->toHaveKey('clone');
    $definition = $commands['clone']->getDefinition();

    expect($definition->hasArgument('repo'))->toBeTrue();
    expect($definition->hasArgument('directory'))->toBeTrue();
    expect($definition->hasOption('branch'))->toBeTrue();
    expect($definition->hasOption('provider'))->toBeTrue();
    expect($definition->hasOption('no-install'))->toBeTrue();
});
